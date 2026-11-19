<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\DeliverySlotRepository;
use Foodwell\Validation\DeliverySlotInput;
use JsonException;
use PDOException;

/**
 * Handles the /api/v1/delivery-slots routes
 *
 *   - Anyone can read slots (index, show).
 *   - Only staff/admin can create, change or delete them.
 *
 * at checkout they send POST /orders with "deliverySlotId". That code (OrderRepository) checks
 * the slot is active, in the future and not full.
 */
final class DeliverySlotController
{
    // The only query parameters the list accepts. Anything else is a 400,
    // so a typo like ?availble=true doesn't silently return everything.
    private const QUERY_KEYS = ['date', 'available'];

    public function __construct(private DeliverySlotRepository $slots)
    {
    }

    /**
     * GET /delivery-slots
     * Example: /delivery-slots?date=2026-11-20&available=true
     */
    public function index(array $params = []): void
    {
        [$date, $available, $problems] = $this->readListQuery($_GET);
        if ($problems !== []) {
            Response::error(400, 'Invalid query parameters.', [], $problems);
            return;
        }

        Response::send(200, ['data' => $this->slots->search($date, $available)], [], 'deliverySlots', 'deliverySlot');
    }

    /** GET /delivery-slots/{id}: the slot (200), or 404 if that id doesn't exist. */
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $slot = $this->slots->findById($id);
        if ($slot === null) {
            Response::error(404, "Delivery slot $id not found.");
            return;
        }
        Response::send(200, $slot, [], 'deliverySlot');
    }

    /**
     * POST /delivery-slots: create a new slot.
     *   201 = created (the Location header says where to find it)
     *   409 = another slot already has the exact same start and end
     *   422 = something in the body is wrong (the response lists each problem)
     */
    public function store(array $params = []): void
    {
        $input = $this->readJson();
        if ($input === null) {
            return;   // readJson() already sent the 400/415 error
        }

        $result = DeliverySlotInput::validate($input);
        $errors = $result['errors'];

        // Extra rule only for NEW slots: it can't start in the past,
        // otherwise nobody could ever book it.
        if (
            !isset($errors['startsAt']) && isset($result['data']['startsAt'])
            && !DeliverySlotInput::isFuture($result['data']['startsAt'])
        ) {
            $errors['startsAt'] = 'startsAt must be in the future.';
        }
        if ($errors !== []) {
            Response::error(422, 'Validation failed.', [], $errors);
            return;
        }

        // If the client didn't send isActive, a new slot starts active.
        $data = $result['data'] + ['isActive' => true];

        try {
            $slot = $this->slots->create($data);
        } catch (PDOException $e) {
            if ($this->isDuplicateWindow($e)) {
                return;   // a clear 409 was already sent
            }
            throw $e;     // any other database problem becomes a 500
        }

        // send() answers in the format negotiated from Accept (JSON, XML or YAML, #65).
        Response::send(201, $slot, ['Location' => $this->itemPath($slot['id'])], 'deliverySlot');
    }

    /**
     * PUT /delivery-slots/{id}: change a slot.
     *
     * The client sends startsAt, endsAt and capacity every time. isActive is
     * optional: if it's left out, the slot keeps whatever it had.
     *
     * Rules once customers have booked the slot:
     *   - Its time window can't move (409). Those customers chose that time.
     *   - Its capacity can't go below the number of bookings (409).
     *   - Deactivating it is always fine: existing orders keep their slot,
     *     and new checkouts can't pick it anymore.
     */
    public function update(array $params): void
    {
        $id = (int) $params['id'];

        $current = $this->slots->lock($id);
        if ($current === null) {
            Response::error(404, "Delivery slot $id not found.");
            return;
        }

        $input = $this->readJson();
        if ($input === null) {
            return;
        }

        $result = DeliverySlotInput::validate($input);
        if ($result['errors'] !== []) {
            Response::error(422, 'Validation failed.', [], $result['errors']);
            return;
        }
        // isActive left out = keep the current value.
        $data = $result['data'] + ['isActive' => $current['is_active']];

        $moved = $data['startsAt'] !== $current['starts_at'] || $data['endsAt'] !== $current['ends_at'];

        // a past slot whose window stays the same can still be edited (e.g. to fix its capacity).
        if ($moved && !DeliverySlotInput::isFuture($data['startsAt'])) {
            Response::error(422, 'Validation failed.', [], ['startsAt' => 'A changed startsAt must be in the future.']);
            return;
        }

        //count the bookings. Safe now, because of the lock in step 1.
        $booked = $this->slots->countBookings($id);
        if ($moved && $booked > 0) {
            Response::error(409, "Delivery slot $id has $booked booked order(s), so its time window cannot change. "
                . 'Create a new slot, or deactivate this one with "isActive": false.');
            return;
        }
        if ($data['capacity'] < $booked) {
            Response::error(409, "Delivery slot $id already has $booked booked order(s); capacity cannot be lower than that.");
            return;
        }

        //save.
        try {
            $slot = $this->slots->update($id, $data);
        } catch (PDOException $e) {
            if ($this->isDuplicateWindow($e)) {
                return;
            }
            throw $e;
        }

        Response::send(200, $slot, [], 'deliverySlot');
    }

    /**
     * DELETE /delivery-slots/{id}
     *   204 = deleted (no body)
     *   404 = no slot with that id
     *   409 = an order uses this slot, so it can't be deleted
     */
    public function destroy(array $params): void
    {
        $id = (int) $params['id'];

        // Lock the slot so no checkout can start using it while we delete it.
        if ($this->slots->lock($id) === null) {
            Response::error(404, "Delivery slot $id not found.");
            return;
        }

        $conflict = "Delivery slot $id is used by orders and cannot be deleted. "
            . 'Deactivate it instead with PUT and "isActive": false.';

        // First we check for orders ourselves, so we can give a friendly message.
        if ($this->slots->hasOrders($id)) {
            Response::error(409, $conflict);
            return;
        }
        try {
            $this->slots->delete($id);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                Response::error(409, $conflict);
                return;
            }
            throw $e;
        }

        http_response_code(204);   // success, and nothing to send back
    }

    /**
     * Reads and checks the list's query string
     *
     * Returns three things:
     *   - the date to filter on ('Y-m-d') or null for "any day"
     *   - true / false for the available filter, or null for "don't filter"
     *   - a list of problems (empty when everything is fine)
     * An empty value like ?date= is treated the same as not sending it.
     *
     * @return array{0:?string,1:?bool,2:array<string,string>}
     */
    private function readListQuery(array $get): array
    {
        $problems = [];
        foreach ($get as $key => $value) {
            if (!in_array($key, self::QUERY_KEYS, true)) {
                $problems[(string) $key] = 'Unknown query parameter. Allowed: ' . implode(', ', self::QUERY_KEYS) . '.';
            } elseif (!is_string($value)) {
                // PHP turns ?date[]=x into an array. We only accept one plain value.
                $problems[$key] = 'Give this parameter once, as a single value.';
            }
        }

        $date = null;
        if (isset($get['date']) && is_string($get['date']) && $get['date'] !== '') {
            $parts = preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $get['date'], $m) === 1 ? $m : null;
            if ($parts === null || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $problems['date'] = 'date must be a real calendar day written YYYY-MM-DD (UTC).';
            } else {
                $date = $get['date'];
            }
        }

        $available = null;
        if (isset($get['available']) && is_string($get['available']) && $get['available'] !== '') {
            if ($get['available'] === 'true') {
                $available = true;
            } elseif ($get['available'] === 'false') {
                $available = false;
            } else {
                $problems['available'] = 'available must be exactly true or false.';
            }
        }

        return [$date, $available, $problems];
    }

    /**
     * Returns true if the PDOException is a duplicate-window error, and sends a 409 response.
     *Returns false for any other PDOException.
     */
    private function isDuplicateWindow(PDOException $e): bool
    {
        if (($e->errorInfo[1] ?? null) !== 1062) {
            return false;
        }
        Response::error(409, 'A delivery slot with the same startsAt and endsAt already exists.');
        return true;
    }

    private function itemPath(int $id): string
    {
        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/api/v1/delivery-slots', PHP_URL_PATH), '/');
        return $path . '/' . $id;
    }

    /**
     * Reads the request body as a JSON object, or sends an error and returns null:
     *   415 = the Content-Type header isn't application/json
     *   400 = the JSON is broken, or it's not an object (e.g. an array)
     */
    private function readJson(): ?array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($type !== 'application/json') {
            Response::error(415, 'This operation requires Content-Type: application/json.');
            return null;
        }
        try {
            $input = json_decode((string) file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Response::error(400, 'Malformed JSON body.');
            return null;
        }
        // Decoding into objects (not arrays) lets us tell {} apart from [].
        if (!$input instanceof \stdClass) {
            Response::error(400, 'The request body must be a JSON object.');
            return null;
        }
        return (array) $input;
    }
}

<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\DeliverySlotRepository;
use Foodwell\Validation\DeliverySlotInput;
use JsonException;
use PDOException;

final class DeliverySlotController
{
    private const QUERY_KEYS = ['date', 'available'];

    public function __construct(private DeliverySlotRepository $slots)
    {
    }

    /** GET /delivery-slots?date=2026-11-20&available=true */
    public function index(array $params = []): void
    {
        [$date, $available, $problems] = $this->readListQuery($_GET);
        if ($problems !== []) {
            Response::error(400, 'Invalid query parameters.', [], $problems);
            return;
        }

        Response::send(200, ['data' => $this->slots->search($date, $available)], [], 'deliverySlots', 'deliverySlot');
    }

    /** GET /delivery-slots/{id} -> 200 DeliverySlot | 404 */
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

    /** POST /delivery-slots -> 201 + Location | 409 same window exists | 422 */
    public function store(array $params = []): void
    {
        $input = $this->readJson();
        if ($input === null) {
            return;
        }

        $result = DeliverySlotInput::validate($input);
        $errors = $result['errors'];

        if (!isset($errors['startsAt']) && isset($result['data']['startsAt'])
            && !DeliverySlotInput::isFuture($result['data']['startsAt'])) {
            $errors['startsAt'] = 'startsAt must be in the future.';
        }
        if ($errors !== []) {
            Response::error(422, 'Validation failed.', [], $errors);
            return;
        }

        $data = $result['data'] + ['isActive' => true]; 

        try {
            $slot = $this->slots->create($data);
        } catch (PDOException $e) {
            if ($this->isDuplicateWindow($e)) {
                return;
            }
            throw $e;
        }

        Response::json(201, $slot, ['Location' => $this->itemPath($slot['id'])]);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];

        //no checkout can book this slot until we finish.
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
        $data = $result['data'] + ['isActive' => $current['is_active']];

        $moved = $data['startsAt'] !== $current['starts_at'] || $data['endsAt'] !== $current['ends_at'];
        if ($moved && !DeliverySlotInput::isFuture($data['startsAt'])) {
            Response::error(422, 'Validation failed.', [], ['startsAt' => 'A changed startsAt must be in the future.']);
            return;
        }

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

        try {
            $slot = $this->slots->update($id, $data);
        } catch (PDOException $e) {
            if ($this->isDuplicateWindow($e)) {
                return;
            }
            throw $e;
        }

        Response::json(200, $slot);
    }

    /** DELETE /delivery-slots/{id} -> 204 | 404 | 409 (orders reference it) */
    public function destroy(array $params): void
    {
        $id = (int) $params['id'];
        if ($this->slots->lock($id) === null) {
            Response::error(404, "Delivery slot $id not found.");
            return;
        }

        $conflict = "Delivery slot $id is used by orders and cannot be deleted. "
            . 'Deactivate it instead with PUT and "isActive": false.';

        // Clear message first
        if ($this->slots->hasOrders($id)) {
            Response::error(409, $conflict);
            return;
        }
        //and respect the database's refusal (fk_orders_delivery_slot RESTRICT).
        try {
            $this->slots->delete($id);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                Response::error(409, $conflict);
                return;
            }
            throw $e;
        }

        http_response_code(204);
    }

    /** @return array{0:?string,1:?bool,2:array<string,string>} date, available, problems */
    private function readListQuery(array $get): array
    {
        $problems = [];
        foreach ($get as $key => $value) {
            if (!in_array($key, self::QUERY_KEYS, true)) {
                $problems[(string) $key] = 'Unknown query parameter. Allowed: ' . implode(', ', self::QUERY_KEYS) . '.';
            } elseif (!is_string($value)) {
                $problems[$key] = 'Give this parameter once, as a single value.';   // ?date[]=... or repeated keys
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

    /** uq_delivery_slots_window: two slots with the exact same start and end. */
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
        if (!$input instanceof \stdClass) {
            Response::error(400, 'The request body must be a JSON object.');
            return null;
        }
        return (array) $input;
    }
}

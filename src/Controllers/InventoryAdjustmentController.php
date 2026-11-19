<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\InventoryAdjustmentRepository;
use Foodwell\Validation\InventoryAdjustmentInput;
use JsonException;

/**
 * Inventory adjustments: the only way to change a product's stock after it
 * has been created (PUT /products/{id} refuses "stock").
 *
 *   GET  /api/v1/products/{id}/inventory-adjustments   -> history of one product
 *   POST /api/v1/products/{id}/inventory-adjustments   -> record a stock change
 *   GET  /api/v1/inventory-adjustments/{adjustmentId}  -> one adjustment
 *
 * All three routes are wrapped by Authorization with the "staff" policy in
 * public/index.php. So by the time a method here runs:
 *   - the caller is a signed-in staff member or administrator (else 401/403),
 *   - $params['actorId'] holds that person's account id,
 *   - a database transaction is open. It is committed if we answer with a
 *     success code, and rolled back if we answer with an error code.
 *
 * There is deliberately no PUT, PATCH or DELETE: an audit log you could edit
 * would not be trustworthy. A wrong adjustment is fixed with a new
 * "correction" adjustment, so the mistake and its fix both stay visible.
 */
final class InventoryAdjustmentController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const MAX_ID = 4294967295; // largest INT UNSIGNED, the product id column type

    public function __construct(private InventoryAdjustmentRepository $adjustments)
    {
    }

    /** GET /products/{id}/inventory-adjustments?page=1&limit=20 */
    public function index(array $params): void
    {
        $productId = $this->validId($params['id']);
        if ($productId === null || !$this->adjustments->productExists($productId)) {
            Response::error(404, "Product {$params['id']} not found.");
            return;
        }

        // Only "page" and "limit" are understood. Anything else (a typo like
        // ?Limit=5, or ?page[]=1) is refused, so the client never gets a page
        // it didn't ask for.
        $page = $this->queryNumber('page', 1, 100000);
        $limit = $this->queryNumber('limit', self::DEFAULT_LIMIT, self::MAX_LIMIT);
        if (array_diff(array_keys($_GET), ['page', 'limit']) !== [] || $page === null || $limit === null) {
            Response::error(400, 'Invalid query parameters. Use page (1 or more) and limit (1 to ' . self::MAX_LIMIT . ').');
            return;
        }

        $result = $this->adjustments->forProduct($productId, $limit, ($page - 1) * $limit);
        $total = $result['total'];

        // Same envelope as GET /customers/{id}/favorites.
        Response::json(200, [
            'data' => $result['items'],
            'metadata' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => (int) ceil($total / $limit), // 0 when there is no history
            ],
        ]);
    }

    /** POST /products/{id}/inventory-adjustments  body: {"quantityChange": 12, "reason": "restock", "note": "..."} */
    public function store(array $params): void
    {
        // 1. Does the product exist? Lock it at the same time, so nobody else
        //    can change its stock until this request is finished.
        $productId = $this->validId($params['id']);
        $stockBefore = $productId === null ? null : $this->adjustments->lockProductStock($productId);
        if ($stockBefore === null) {
            Response::error(404, "Product {$params['id']} not found.");
            return;
        }

        // 2. Is the body valid JSON with the right fields?
        $input = $this->readJson();
        if ($input === null) {
            return; // readJson() already sent the 400 or 415
        }
        $result = InventoryAdjustmentInput::validate($input);
        if ($result['errors'] !== []) {
            Response::error(422, 'Validation failed.', [], $result['errors']);
            return;
        }
        $data = $result['data'];

        // 3. Does the change make sense for the stock we have RIGHT NOW?
        //    The body itself is fine, but the product's current state does not
        //    allow it, so this is a conflict (409) and not a validation error.
        $stockAfter = $stockBefore + $data['quantityChange'];
        if ($stockAfter < 0) {
            Response::error(409, "Product $productId only has $stockBefore in stock, so you cannot remove "
                . abs($data['quantityChange']) . '. Stock cannot go below 0.');
            return;
        }
        if ($stockAfter > InventoryAdjustmentInput::MAX_STOCK) {
            Response::error(409, "Product $productId has $stockBefore in stock; adding {$data['quantityChange']} "
                . 'would go over the maximum of ' . InventoryAdjustmentInput::MAX_STOCK . '.');
            return;
        }

        // 4. Save. actorId comes from the login session (set by Authorization),
        //    never from the body.
        $adjustment = $this->adjustments->record($productId, (int) $params['actorId'], $stockBefore, $data);

        Response::json(201, $adjustment, ['Location' => $this->adjustmentPath($adjustment['id'])]);
    }

    /** GET /inventory-adjustments/{adjustmentId} */
    public function show(array $params): void
    {
        $id = $this->validId($params['adjustmentId']);
        $adjustment = $id === null ? null : $this->adjustments->findById($id);
        if ($adjustment === null) {
            Response::error(404, "Inventory adjustment {$params['adjustmentId']} not found.");
            return;
        }
        Response::json(200, $adjustment);
    }

    /**
     * The router only checks that the id is made of digits. This also refuses
     * 0 and numbers too big for the database column, which simply can't exist.
     */
    private function validId(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_ID]]);
        return $id === false ? null : $id;
    }

    /** Reads ?name=... as a whole number. Missing -> default. Invalid -> null. */
    private function queryNumber(string $name, int $default, int $max): ?int
    {
        if (!array_key_exists($name, $_GET)) {
            return $default;
        }
        $value = $_GET[$name];
        // The regex refuses arrays, signs, spaces, decimals and leading zeros like "007".
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        return $number === false ? null : $number;
    }

    /** "/api/v1/products/109/inventory-adjustments" -> "/api/v1/inventory-adjustments/7" (keeps APP_BASE_PATH if any). */
    private function adjustmentPath(int $id): string
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $prefix = (string) preg_replace('#/products/\d+/inventory-adjustments/?$#', '', $path);
        return $prefix . '/inventory-adjustments/' . $id;
    }

    /** Same body rules as the product routes: JSON only (415), must parse (400), must be an object (400). */
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

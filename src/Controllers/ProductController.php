<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\ProductRepository;
use Foodwell\Validation\ProductInput;
use JsonException;
use PDOException;

final class ProductController
{

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;
    private const MAX_PAGE = 100000;
    private const MAX_SEARCH = 100;
    private const MAX_ID = 4294967295;

    public function __construct(private ProductRepository $products)
    {
    }

    /** /api/v1/products?category=2&search=milk&sort=-price&page=1&limit=10 */
    public function index(array $params = []): void
    {
        [$query, $problems] = $this->readListQuery($_GET);
        if ($problems !== []) {
            Response::error(400, 'Invalid query parameters: ' . implode(' ', $problems));
            return;
        }

        $page = $query['page'];
        $limit = $query['limit'];

        $result = $this->products->search(
            $query['category'],
            $query['search'],
            $query['sort'],
            $limit,
            ($page - 1) * $limit   // Offset
        );

        $total = $result['total'];
        $totalPages = (int) ceil($total / $limit); // 0 when there are no matches

        Response::json(200, [
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages,
                'next' => $page < $totalPages ? $this->pageLink($query, $page + 1) : null,
                'previous' => $page > 1 && $totalPages > 0
                    ? $this->pageLink($query, min($page - 1, $totalPages))
                    : null,
            ],
            'data' => $result['items'],
        ]);
    }

    /** GET /api/v1/products/{id} -> 200 Product | 404 error */
    public function show(array $params): void
    {
        $id = (int) $params['id'];
        $product = $this->products->findById($id);

        if ($product === null) {
            Response::error(404, "Product $id not found.");
            return;
        }
        Response::json(200, $product);
    }

    public function store(array $params = []): void
    {
        $input = $this->readJson();
        if ($input === null) {
            return;
        }

        $result = ProductInput::validate($input, true);
        $errors = $this->withCategoryCheck($result);
        if ($errors !== []) {
            Response::error(422, 'Validation failed.', [], $errors);
            return;
        }

        try {
            $product = $this->products->create($result['data']);
        } catch (PDOException $e) {
            if ($this->isMissingCategory($e)) {
                return;
            }
            throw $e;
        }

        Response::json(201, $product, ['Location' => $this->itemPath($product['id'])]);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $current = $this->products->findById($id);
        if ($current === null) {
            Response::error(404, "Product $id not found.");
            return;
        }

        $input = $this->readJson();
        if ($input === null) { return; }

        $result = ProductInput::validate($input, false);
        $errors = $this->withCategoryCheck($result);
        if ($errors !== []) {
            Response::error(422, 'Validation failed.', [], $errors);
            return;
        }

        // isActive left out of the body = keep the current value.
        $data = $result['data'] + ['isActive' => $current['isActive']];

        try {
            $product = $this->products->update($id, $data);
        } catch (PDOException $e) {
            if ($this->isMissingCategory($e)) { return; }
            throw $e;
        }

        Response::json(200, $product);
    }

    public function destroy(array $params): void
    {
        $id = (int) $params['id'];
        if ($this->products->findById($id) === null) {
            Response::error(404, "Product $id not found.");
            return;
        }

        $conflict = "Product $id has inventory history and cannot be deleted. "
                  . 'Deactivate it instead with PUT and "isActive": false.';

        // Check first for a clear message...
        if ($this->products->hasInventoryHistory($id)) {
            Response::error(409, $conflict);
            return;
        }

        // ...and still catch the database's refusal if history appears in between.
        try {
            $this->products->delete($id);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) { // 1451: row still referenced (FK RESTRICT)
                Response::error(409, $conflict);
                return;
            }
            throw $e;
        }

        http_response_code(204); // success, no body
    }

    private function readListQuery(array $get): array
    {
        $problems = [];

        $given = static fn(string $key): bool => array_key_exists($key, $get) && $get[$key] !== '';

        $wholeNumber = static function (string $key, int $min, int $max) use ($get, &$problems): ?int {
            $value = is_string($get[$key]) ? filter_var($get[$key], FILTER_VALIDATE_INT) : false;
            if ($value === false || $value < $min || $value > $max) {
                $problems[] = "$key must be a whole number from $min to $max.";
                return null;
            }
            return $value;
        };

        $page = $given('page') ? $wholeNumber('page', 1, self::MAX_PAGE) : 1;
        $limit = $given('limit') ? $wholeNumber('limit', 1, self::MAX_LIMIT) : self::DEFAULT_LIMIT;
        $category = $given('category') ? $wholeNumber('category', 1, self::MAX_ID) : null;
        $search = null;

        if ($given('search')) {
            if (!is_string($get['search'])) {
                $problems[] = 'search must be text.';
            } else {
                $search = trim($get['search']);
                if (mb_strlen($search) > self::MAX_SEARCH) {
                    $problems[] = 'search must be at most ' . self::MAX_SEARCH . ' characters.';
                }
                if ($search === '') {
                    $search = null;
                }
            }
        }

        $sort = null;
        if ($given('sort')) {
            if (!is_string($get['sort']) || !array_key_exists($get['sort'], ProductRepository::SORTS)) {
                $problems[] = 'sort must be one of: ' . implode(', ', array_keys(ProductRepository::SORTS)) . '.';
            } else {
                $sort = $get['sort'];
            }
        }

        return [
            [
                'category' => $category,
                'search' => $search,
                'sort' => $sort,
                'page' => $page ?? 1,
                'limit' => $limit ?? self::DEFAULT_LIMIT,
            ],
            $problems
        ];
    }

    private function pageLink(array $query, int $page): string
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/api/v1/products', PHP_URL_PATH);

        $params = array_filter([
            'category' => $query['category'],
            'search' => $query['search'],
            'sort' => $query['sort'],
        ], static fn($v) => $v !== null);
        $params['page'] = $page;
        $params['limit'] = $query['limit'];

        return $path . '?' . http_build_query($params);
    }

    private function withCategoryCheck(array $result): array
    {
        $errors = $result['errors'];
        if (isset($result['data']['categoryId'])
            && !$this->products->categoryExists($result['data']['categoryId'])) {
            $errors['categoryId'] = 'This category does not exist.';
        }
        return $errors;
    }

     private function isMissingCategory(PDOException $e): bool
    {
        if (($e->errorInfo[1] ?? null) !== 1452) {
            return false;
        }
        Response::error(422, 'Validation failed.', [], ['categoryId' => 'This category does not exist.']);
        return true;
    }

    private function itemPath(int $id): string
    {
        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/api/v1/products', PHP_URL_PATH), '/');
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


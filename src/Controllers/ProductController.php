<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\ProductRepository;

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
}


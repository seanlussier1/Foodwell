<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use Foodwell\Repositories\ProductRepository;

final class ProductController
{
    public function __construct(private ProductRepository $products)
    {
    }

    /** GET /api/v1/products -> 200 {"data": [...]}  (empty list is still 200) */
    public function index(array $params = []): void
    {
        Response::json(200, ['data' => $this->products->findAll()]);
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
}
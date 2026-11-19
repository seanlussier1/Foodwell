<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\Response;
use PDO;

final class FavoriteController
{
    public function __construct(private PDO $db) {}

    public function index(array $params): void
    {
        $page = $this->queryNumber('page', 1, 2147483647);
        $limit = $this->queryNumber('limit', 20, 100);
        if (array_diff(array_keys($_GET), ['page', 'limit']) || $page === null || $limit === null) {
            Response::error(400, 'Invalid pagination parameters.');
            return;
        }
        $count = $this->db->prepare('SELECT COUNT(*) FROM customer_favorites WHERE customer_id = ?');
        $count->execute([(int)$params['id']]);
        $total = (int)$count->fetchColumn();
        $query = $this->db->prepare('SELECT f.product_id, f.created_at, p.name, p.price, p.unit, p.stock, p.category_id, c.name AS category_name, p.image_url, p.is_active FROM customer_favorites f JOIN products p ON p.id = f.product_id JOIN categories c ON c.id = p.category_id WHERE f.customer_id = :id ORDER BY f.product_id LIMIT :limit OFFSET :offset');
        $query->bindValue(':id', (int)$params['id'], PDO::PARAM_INT);
        $query->bindValue(':limit', $limit, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $query->execute();
        $data = [];
        foreach ($query->fetchAll() as $row) {
            $data[] = ['productId' => (int)$row['product_id'], 'createdAt' => str_replace(' ', 'T', $row['created_at']) . 'Z', 'product' => [
                'id' => (int)$row['product_id'], 'name' => $row['name'], 'price' => (float)$row['price'], 'unit' => $row['unit'], 'stock' => (int)$row['stock'], 'categoryId' => (int)$row['category_id'], 'categoryName' => $row['category_name'], 'imageUrl' => $row['image_url'], 'isActive' => (bool)$row['is_active'],
            ]];
        }
        Response::json(200, ['data' => $data, 'metadata' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'totalPages' => (int)ceil($total / $limit)]]);
    }

    public function save(array $params): void
    {
        if (!$this->validProductId($params)) return;
        // Lock the product while inserting so a concurrent deletion cannot race the foreign key.
        $product = $this->db->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
        $product->execute([(int)$params['productId']]);
        if ($product->fetchColumn() === false) { Response::error(404, 'Product not found.'); return; }
        $stmt = $this->db->prepare('INSERT INTO customer_favorites (customer_id, product_id, created_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE product_id = product_id');
        $stmt->execute([(int)$params['id'], (int)$params['productId']]);
        http_response_code(204);
    }

    public function remove(array $params): void
    {
        if (!$this->validProductId($params)) return;
        $stmt = $this->db->prepare('DELETE FROM customer_favorites WHERE customer_id = ? AND product_id = ?');
        $stmt->execute([(int)$params['id'], (int)$params['productId']]);
        http_response_code(204);
    }

    private function validProductId(array $params): bool
    {
        if (filter_var($params['productId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]) !== false) return true;
        Response::error(404, 'Product not found.');
        return false;
    }

    private function queryNumber(string $name, int $default, int $max): ?int
    {
        if (!array_key_exists($name, $_GET)) return $default;
        $value = $_GET[$name];
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) return null;
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        return $number === false ? null : $number;
    }
}

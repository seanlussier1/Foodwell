<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\ResourceInput;
use Foodwell\Http\Response;
use PDO;
use PDOException;

final class ReviewController
{
    private const SELECT = 'SELECT id, product_id, customer_id, rating, comment,
        UNIX_TIMESTAMP(created_at) AS created_epoch, UNIX_TIMESTAMP(updated_at) AS updated_epoch
        FROM product_reviews';

    public function __construct(private PDO $db) {}

    public function index(array $params): void
    {
        $paging = ResourceInput::paging($_GET);
        if ($paging === null) {
            return;
        }
        $productId = ResourceInput::id($params['id']);
        if (!$this->productExists($productId)) {
            Response::error(404, 'Product not found.');
            return;
        }
        [$limit, $offset] = $paging;
        $query = $this->db->prepare(self::SELECT . ' WHERE product_id = ?
            ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
        $query->bindValue(1, $productId, PDO::PARAM_INT);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->bindValue(3, $offset, PDO::PARAM_INT);
        $query->execute();
        Response::json(200, ['data' => array_map([self::class, 'format'], $query->fetchAll())]);
    }

    public function create(array $params): void
    {
        $input = $this->readFields(false);
        if ($input === null) {
            return;
        }
        $productId = ResourceInput::id($params['id']);
        if (!$this->productExists($productId, true)) {
            Response::error(404, 'Product not found.');
            return;
        }
        $insert = $this->db->prepare('INSERT INTO product_reviews (product_id, customer_id, rating, comment)
            VALUES (?, ?, ?, ?)');
        try {
            $insert->execute([$productId, $params['actorId'], $input['rating'], $input['comment']]);
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            Response::error(409, 'You already reviewed this product.');
            return;
        }
        $this->respond((int) $this->db->lastInsertId(), 201, true);
    }

    public function show(array $params): void
    {
        $this->respond(ResourceInput::id($params['reviewId']));
    }

    public function update(array $params): void
    {
        $input = $this->readFields(true);
        if ($input === null) {
            return;
        }
        $id = ResourceInput::id($params['reviewId']);
        $update = $this->db->prepare('UPDATE product_reviews
            SET rating = COALESCE(?, rating), comment = COALESCE(?, comment) WHERE id = ?');
        $update->execute([$input['rating'] ?? null, $input['comment'] ?? null, $id]);
        $this->respond($id, 200, true);
    }

    public function delete(array $params): void
    {
        $delete = $this->db->prepare('DELETE FROM product_reviews WHERE id = ?');
        $delete->execute([ResourceInput::id($params['reviewId'])]);
        http_response_code(204);
    }

    private function readFields(bool $patch): ?array
    {
        $input = ResourceInput::jsonObject();
        if ($input === null) {
            return null;
        }
        $errors = [];
        foreach (array_diff_key($input, ['rating' => true, 'comment' => true]) as $field => $value) {
            $errors[$field] = 'This field is not allowed.';
        }
        if ((!$patch || array_key_exists('rating', $input))
            && (!is_int($input['rating'] ?? null) || $input['rating'] < 1 || $input['rating'] > 5)) {
            $errors['rating'] = 'Use an integer from 1 to 5.';
        }
        if ((!$patch || array_key_exists('comment', $input))
            && (!is_string($input['comment'] ?? null) || str_contains($input['comment'], "\0")
                || mb_strlen($input['comment'], 'UTF-8') > 2000)) {
            $errors['comment'] = 'Use a string of at most 2000 characters.';
        }
        if ($errors !== [] || $input === []) {
            Response::error(422, 'Provide valid review fields.', [], $errors);
            return null;
        }
        return $input;
    }

    private function productExists(?int $id, bool $lock = false): bool
    {
        if ($id === null) {
            return false;
        }
        $query = $this->db->prepare('SELECT id FROM products WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        return $query->fetchColumn() !== false;
    }

    private function respond(?int $id, int $status = 200, bool $lock = false): void
    {
        $query = $this->db->prepare(self::SELECT . ' WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $row = $query->fetch();
        if ($row === false) {
            Response::error(404, 'Review not found.');
            return;
        }
        Response::json($status, self::format($row));
    }

    private static function format(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'productId' => (int) $row['product_id'],
            'customerId' => (int) $row['customer_id'],
            'rating' => (int) $row['rating'],
            'comment' => $row['comment'],
            'createdAt' => gmdate(DATE_ATOM, (int) $row['created_epoch']),
            'updatedAt' => gmdate(DATE_ATOM, (int) $row['updated_epoch']),
        ];
    }
}

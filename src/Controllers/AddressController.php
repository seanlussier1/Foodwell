<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Http\ResourceInput;
use Foodwell\Http\Response;
use PDO;

final class AddressController
{
    private const FIELDS = [
        'label' => ['label', 50],
        'recipientName' => ['recipient_name', 200],
        'addressLine1' => ['address_line1', 150],
        'addressLine2' => ['address_line2', 150],
        'city' => ['city', 100],
        'province' => ['province', 50],
        'postalCode' => ['postal_code', 20],
        'countryCode' => ['country_code', 2],
        'phone' => ['phone', 30],
    ];
    private const NULLABLE = ['addressLine2', 'phone'];
    private const REQUIRED = ['label', 'recipientName', 'addressLine1', 'city', 'province', 'postalCode'];
    private const SELECT = 'SELECT a.*, UNIX_TIMESTAMP(created_at) AS created_epoch,
        UNIX_TIMESTAMP(updated_at) AS updated_epoch FROM customer_addresses a';

    public function __construct(private PDO $db) {}

    public function index(array $params): void
    {
        $paging = ResourceInput::paging($_GET);
        if ($paging === null) {
            return;
        }
        [$limit, $offset] = $paging;
        $query = $this->db->prepare(self::SELECT . ' WHERE customer_id = ? ORDER BY id LIMIT ? OFFSET ? FOR UPDATE');
        $query->bindValue(1, $params['actorId'], PDO::PARAM_INT);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->bindValue(3, $offset, PDO::PARAM_INT);
        $query->execute();
        Response::json(200, ['data' => array_map([self::class, 'format'], $query->fetchAll())]);
    }

    public function create(array $params): void
    {
        $fields = $this->readFields(false);
        if ($fields === null) {
            return;
        }
        $columns = implode(', ', array_keys($fields));
        $values = implode(', ', array_fill(0, count($fields), '?'));
        $insert = $this->db->prepare("INSERT INTO customer_addresses (customer_id, $columns) VALUES (?, $values)");
        $insert->execute([$params['actorId'], ...array_values($fields)]);
        $this->respond($params['actorId'], (int) $this->db->lastInsertId(), 201);
    }

    public function show(array $params): void
    {
        $this->respond($params['actorId'], ResourceInput::id($params['addressId']));
    }

    public function update(array $params): void
    {
        $id = ResourceInput::id($params['addressId']);
        if ($id === null || $this->find($params['actorId'], $id) === false) {
            Response::error(404, 'Saved address not found.');
            return;
        }
        $fields = $this->readFields(true);
        if ($fields === null) {
            return;
        }
        $assignments = implode(', ', array_map(static fn ($column) => "$column = ?", array_keys($fields)));
        $update = $this->db->prepare("UPDATE customer_addresses SET $assignments WHERE customer_id = ? AND id = ?");
        $update->execute([...array_values($fields), $params['actorId'], $id]);
        $this->respond($params['actorId'], $id);
    }

    public function delete(array $params): void
    {
        $id = ResourceInput::id($params['addressId']);
        $delete = $this->db->prepare('DELETE FROM customer_addresses WHERE customer_id = ? AND id = ?');
        $delete->execute([$params['actorId'], $id]);
        if ($delete->rowCount() === 0) {
            Response::error(404, 'Saved address not found.');
            return;
        }
        http_response_code(204);
    }

    private function readFields(bool $patch): ?array
    {
        $input = ResourceInput::jsonObject();
        if ($input === null) {
            return null;
        }
        $errors = [];
        $fields = [];
        foreach ($patch ? [] : self::REQUIRED as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = 'This field is required.';
            }
        }
        foreach ($input as $field => $value) {
            if (!isset(self::FIELDS[$field])) {
                $errors[$field] = 'This field is not allowed.';
                continue;
            }
            [$column, $max] = self::FIELDS[$field];
            $nullable = in_array($field, self::NULLABLE, true);
            if ($value === null && $nullable) {
                $fields[$column] = null;
                continue;
            }
            if (!is_string($value) || str_contains($value, "\0")
                || mb_strlen(trim($value), 'UTF-8') > $max || (!$nullable && trim($value) === '')) {
                $errors[$field] = "Use a valid string of at most $max characters.";
                continue;
            }
            $value = trim($value);
            if ($field === 'countryCode' && $value !== 'CA') {
                $errors[$field] = 'Only CA is supported.';
                continue;
            }
            $fields[$column] = $value;
        }
        if ($errors !== [] || $fields === []) {
            Response::error(422, 'Provide valid address fields.', [], $errors);
            return null;
        }
        return $fields;
    }

    private function find(int $customerId, ?int $id): array|false
    {
        if ($id === null) {
            return false;
        }
        $query = $this->db->prepare(self::SELECT . ' WHERE customer_id = ? AND id = ? FOR UPDATE');
        $query->execute([$customerId, $id]);
        return $query->fetch();
    }

    private function respond(int $customerId, ?int $id, int $status = 200): void
    {
        $row = $this->find($customerId, $id);
        if ($row === false) {
            Response::error(404, 'Saved address not found.');
            return;
        }
        Response::json($status, self::format($row));
    }

    private static function format(array $row): array
    {
        $address = ['id' => (int) $row['id'], 'customerId' => (int) $row['customer_id']];
        foreach (self::FIELDS as $key => [$column]) {
            $address[$key] = $row[$column];
        }
        $address['createdAt'] = gmdate(DATE_ATOM, (int) $row['created_epoch']);
        $address['updatedAt'] = gmdate(DATE_ATOM, (int) $row['updated_epoch']);
        return $address;
    }
}

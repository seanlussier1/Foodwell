<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

final class CustomerRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, first_name, last_name, email, address, phone FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) { return null; }
        return [
            'id' => (int) $row['id'], 'firstName' => $row['first_name'],
            'lastName' => $row['last_name'], 'email' => $row['email'],
            'address' => $row['address'], 'phone' => $row['phone'],
        ];
    }

    /** Only customer-role accounts appear; staff/admin accounts are not customers. */
    public function findPage(array $filters): array
    {
        $where = "role = 'customer'";
        $values = [];
        if ($filters['search'] !== '') {
            // Literal substring matching; '%' and '_' are not wildcards.
            $where .= ' AND (LOCATE(:firstSearch, first_name) > 0 OR LOCATE(:lastSearch, last_name) > 0)';
            $values['firstSearch'] = $filters['search'];
            $values['lastSearch'] = $filters['search'];
        }
        if ($filters['isActive'] !== null) {
            $where .= ' AND is_active = :active';
            $values['active'] = (int) $filters['isActive'];
        }
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM customers WHERE $where");
        $count->execute($values);
        $total = (int) $count->fetchColumn();
        $stmt = $this->pdo->prepare(
            "SELECT id, first_name, last_name, email, address, phone, is_active
             FROM customers WHERE $where ORDER BY id ASC LIMIT :limit OFFSET :offset"
        );
        foreach ($values as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $filters['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($filters['page'] - 1) * $filters['limit'], PDO::PARAM_INT);
        $stmt->execute();
        $data = [];
        foreach ($stmt->fetchAll() as $row) {
            $data[] = [
                'id' => (int) $row['id'], 'firstName' => $row['first_name'],
                'lastName' => $row['last_name'], 'email' => $row['email'],
                'address' => $row['address'], 'phone' => $row['phone'],
                'isActive' => (bool) $row['is_active'],
            ];
        }
        return ['total' => $total, 'data' => $data];
    }

    /** Retain history and revoke sessions atomically; repeated deactivation is safe. */
    public function deactivate(int $id): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT role FROM customers WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            $row = $lock->fetch();
            if ($row === false) { $this->pdo->rollBack(); return null; }
            if ($row['role'] !== 'customer') {
                $this->pdo->rollBack();
                return ['restricted' => true];
            }
            $update = $this->pdo->prepare('UPDATE customers SET is_active = 0 WHERE id = :id AND is_active = 1');
            $update->execute(['id' => $id]);
            $sessions = $this->pdo->prepare('UPDATE auth_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE customer_id = :id AND revoked_at IS NULL');
            $sessions->execute(['id' => $id]);
            $customer = $this->findById($id);
            $customer['isActive'] = false;
            $this->pdo->commit();
            return $customer;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    /** PUT replaces profile fields only; missing nullable fields become null. */
    public function updateProfile(int $id, array $input): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM customers WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            if ($lock->fetch() === false) {
                $this->pdo->rollBack();
                return null;
            }
            $stmt = $this->pdo->prepare(
                'UPDATE customers SET first_name = :first_name, last_name = :last_name,
                 email = :email, address = :address, phone = :phone WHERE id = :id'
            );
            $stmt->execute([
                'first_name' => $input['firstName'], 'last_name' => $input['lastName'],
                'email' => $input['email'], 'address' => $input['address'],
                'phone' => $input['phone'], 'id' => $id,
            ]);
            $customer = $this->findById($id);
            $this->pdo->commit();
            return $customer;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
    }

    /** Only validated registration fields reach this method. */
    public function register(array $input): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (first_name, last_name, email, password_hash, address, phone, role, is_active)
             VALUES (:first_name, :last_name, :email, :password_hash, :address, :phone, \'customer\', 1)'
        );
        $stmt->execute([
            'first_name' => $input['firstName'],
            'last_name' => $input['lastName'],
            'email' => $input['email'],
            'password_hash' => password_hash($input['password'], PASSWORD_BCRYPT),
            'address' => $input['address'],
            'phone' => $input['phone'],
        ]);
        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'firstName' => $input['firstName'],
            'lastName' => $input['lastName'],
            'email' => $input['email'],
            'address' => $input['address'],
            'phone' => $input['phone'],
        ];
    }
}

<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

final class CategoryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Return all categories in ID order. */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name FROM categories ORDER BY id ASC'
        );

        $categories = [];
        foreach ($stmt->fetchAll() as $row) {
            $categories[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
            ];
        }

        return $categories;
    }

    /** Find one category by ID. */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM categories WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
        ];
    }

    /** Create a category using validated input. */
    public function create(string $name): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (name) VALUES (:name)'
        );
        $stmt->execute(['name' => $name]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'name' => $name,
        ];
    }

    /**
     * Delete a category only when no products use it.
     *
     * Returns:
     * - null when the category does not exist
     * - ['inUse' => true] when products use the category
     * - ['id' => ..., 'name' => ...] when deletion succeeds
     */
    public function delete(int $id): ?array
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, name FROM categories WHERE id = :id FOR UPDATE'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            if ($row === false) {
                $this->pdo->rollBack();
                return null;
            }

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM products WHERE category_id = :id'
            );
            $stmt->execute(['id' => $id]);

            if ((int) $stmt->fetchColumn() > 0) {
                $this->pdo->rollBack();
                return ['inUse' => true];
            }

            $stmt = $this->pdo->prepare(
                'DELETE FROM categories WHERE id = :id'
            );
            $stmt->execute(['id' => $id]);

            $category = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
            ];

            $this->pdo->commit();

            return $category;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
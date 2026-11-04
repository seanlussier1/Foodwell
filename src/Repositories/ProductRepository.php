<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

final class ProductRepository
{
    private const SELECT = 'SELECT p.id, p.name, p.price, p.unit, p.stock, p.category_id,
                                   c.name AS category_name, p.image_url, p.is_active
                            FROM products p
                            JOIN categories c ON c.id = p.category_id';
    // Sort values
    public const SORTS = [
        'name' => 'p.name ASC, p.id ASC',
        '-name' => 'p.name DESC, p.id DESC',
        'price' => 'p.price ASC, p.id ASC',
        '-price' => 'p.price DESC, p.id DESC',
    ];

    // Ascending when no order is chosen
    private const DEFAULT_ORDER = 'p.id ASC';

    public function __construct(private PDO $pdo)
    {
    }

    // /** @return array<int,array<string,mixed>> */
    // public function findAll(): array
    // {
    //     $rows = $this->pdo->query(self::SELECT . ' ORDER BY p.id')->fetchAll();
    //     return array_map([$this, 'toProduct'], $rows);
    // }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE p.id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $this->toProduct($row);
    }

    /** Maps a DB row to the documented public Product shape. */
    private function toProduct(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'price' => (float) $row['price'],
            'unit' => $row['unit'],
            'stock' => (int) $row['stock'],
            'categoryId' => (int) $row['category_id'],
            'categoryName' => $row['category_name'],
            'imageUrl' => $row['image_url'],
            'isActive' => (bool) $row['is_active'],
        ];
    }

    public function search(?int $category, ?string $search, ?string $sort, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        // if category is given it will search by category
        if ($category !== null) {
            $where[] = 'p.category_id = :category';
            $params[':category'] = $category;
        }
        if ($search !== null) {
            $where[] = 'p.name LIKE :search';
            //In case user adds percentage when searching
            $params[':search'] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        // 1. Total matches, for meta.total / meta.totalPages.
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM products p' . $whereSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        // 2. Just the rows of the requested page.
        $orderBy = $sort === null ? self::DEFAULT_ORDER : self::SORTS[$sort];
        $stmt = $this->pdo->prepare(self::SELECT . $whereSql . " ORDER BY $orderBy LIMIT :limit OFFSET :offset");

        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map([$this, 'toProduct'], $stmt->fetchAll()),
            'total' => $total,
        ];
    }

    public function categoryExists(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM categories WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function hasInventoryHistory(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM inventory_adjustments WHERE product_id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function create(array $p): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (name, price, unit, stock, category_id, image_url)
             VALUES (:name, :price, :unit, :stock, :category_id, :image_url)'
        );
        $stmt->bindValue(':name', $p['name']);
        $stmt->bindValue(':price', self::money($p['price']));
        $stmt->bindValue(':unit', $p['unit']);
        $stmt->bindValue(':stock', $p['stock'], PDO::PARAM_INT);
        $stmt->bindValue(':category_id', $p['categoryId'], PDO::PARAM_INT);
        $stmt->bindValue(':image_url', $p['imageUrl']); // PHP null -> SQL NULL
        $stmt->execute();

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $p): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products
                SET name = :name, price = :price, unit = :unit,
                    category_id = :category_id, image_url = :image_url, is_active = :is_active
              WHERE id = :id'
        );
        $stmt->bindValue(':name', $p['name']);
        $stmt->bindValue(':price', self::money($p['price']));
        $stmt->bindValue(':unit', $p['unit']);
        $stmt->bindValue(':category_id', $p['categoryId'], PDO::PARAM_INT);
        $stmt->bindValue(':image_url', $p['imageUrl']);
        $stmt->bindValue(':is_active', $p['isActive'] ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM products WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}



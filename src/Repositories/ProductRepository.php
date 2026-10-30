<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

final class ProductRepository
{
    private const SELECT = 'SELECT p.id, p.name, p.price, p.unit, p.stock, p.category_id,
                                   c.name AS category_name, p.image_url
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



}

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

    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function findAll(): array
    {
        $rows = $this->pdo->query(self::SELECT . ' ORDER BY p.id')->fetchAll();
        return array_map([$this, 'toProduct'], $rows);
    }

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
            'id'           => (int) $row['id'],
            'name'         => $row['name'],
            'price'        => (float) $row['price'],
            'unit'         => $row['unit'],
            'stock'        => (int) $row['stock'],
            'categoryId'   => (int) $row['category_id'],
            'categoryName' => $row['category_name'],
            'imageUrl'     => $row['image_url'],
        ];
    }
}

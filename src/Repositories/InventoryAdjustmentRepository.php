<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

/**
 * Every SQL query for the inventory_adjustments table lives here.
 *
 * The table is an audit log: rows are only ever added, never changed or
 * deleted. That is why this class has no update() or delete() method.
 */
final class InventoryAdjustmentRepository
{
    private const SELECT = 'SELECT id, product_id, actor_id, quantity_change, stock_before,
                                   stock_after, reason, note, created_at
                              FROM inventory_adjustments';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Reads a product's current stock AND locks that product row until the
     * transaction ends.
     *
     * Why lock? Picture two staff members adjusting the same product at the
     * same moment. Without the lock, both could read "stock = 10", both add
     * their change to 10, and one of the two changes would be lost. With
     * FOR UPDATE, the second request waits until the first one is finished,
     * then reads the new, correct stock.
     *
     * The lock only works inside a transaction. The Authorization wrapper
     * already opens one around every protected route, and commits it only
     * when the response is a success.
     *
     * @return int|null the stock, or null when the product does not exist
     */
    public function lockProductStock(int $productId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $stock = $stmt->fetchColumn();
        return $stock === false ? null : (int) $stock;
    }

    public function productExists(int $productId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM products WHERE id = :id');
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Saves one adjustment: changes the product's stock and writes the audit
     * row. Both happen in the same transaction, so either both are saved or
     * neither is.
     *
     * @param array{quantityChange:int, reason:string, note:?string} $input
     */
    public function record(int $productId, int $actorId, int $stockBefore, array $input): array
    {
        $stockAfter = $stockBefore + $input['quantityChange'];

        // 1. Change the stock on the product itself.
        $update = $this->pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
        $update->bindValue(':stock', $stockAfter, PDO::PARAM_INT);
        $update->bindValue(':id', $productId, PDO::PARAM_INT);
        $update->execute();

        // 2. Write the audit row. The actor and both balances come from the
        //    server, never from the request body. The time is saved in UTC,
        //    like the rest of the project (favorites, sessions).
        $insert = $this->pdo->prepare(
            'INSERT INTO inventory_adjustments
                 (product_id, actor_id, quantity_change, stock_before, stock_after, reason, note, created_at)
             VALUES (:product, :actor, :change, :before, :after, :reason, :note, UTC_TIMESTAMP())'
        );
        $insert->bindValue(':product', $productId, PDO::PARAM_INT);
        $insert->bindValue(':actor', $actorId, PDO::PARAM_INT);
        $insert->bindValue(':change', $input['quantityChange'], PDO::PARAM_INT);
        $insert->bindValue(':before', $stockBefore, PDO::PARAM_INT);
        $insert->bindValue(':after', $stockAfter, PDO::PARAM_INT);
        $insert->bindValue(':reason', $input['reason']);
        $insert->bindValue(':note', $input['note']); // PHP null -> SQL NULL
        $insert->execute();

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::toAdjustment($row);
    }

    /**
     * One page of a product's history, newest first.
     * Sorting by id (not only by date) keeps the order stable: two
     * adjustments made in the same second never swap places between pages.
     *
     * @return array{items: array<int,array>, total: int}
     */
    public function forProduct(int $productId, int $limit, int $offset): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_adjustments WHERE product_id = :id');
        $count->bindValue(':id', $productId, PDO::PARAM_INT);
        $count->execute();

        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE product_id = :id ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map([self::class, 'toAdjustment'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** Turns a database row (snake_case, everything as text) into the API shape (camelCase, real types). */
    private static function toAdjustment(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'productId' => (int) $row['product_id'],
            'actorId' => (int) $row['actor_id'],
            'quantityChange' => (int) $row['quantity_change'],
            'stockBefore' => (int) $row['stock_before'],
            'stockAfter' => (int) $row['stock_after'],
            'reason' => $row['reason'],
            'note' => $row['note'],
            // "2026-11-19 16:55:00" -> "2026-11-19T16:55:00Z", same format as favorites and sessions.
            'createdAt' => str_replace(' ', 'T', $row['created_at']) . 'Z',
        ];
    }
}

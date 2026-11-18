<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

final class DeliverySlotRepository
{
    // Booked = non-cancelled orders per slot
    private const SELECT = "SELECT s.id, s.starts_at, s.ends_at, s.capacity, s.is_active,
                                   s.created_at, s.updated_at,
                                   COALESCE(b.booked, 0) AS booked,
                                   s.starts_at > UTC_TIMESTAMP() AS is_future
                            FROM delivery_slots s
                            LEFT JOIN (
                                SELECT delivery_slot_id, COUNT(*) AS booked
                                FROM orders
                                WHERE status <> 'cancelled' AND delivery_slot_id IS NOT NULL
                                GROUP BY delivery_slot_id
                            ) b ON b.delivery_slot_id = s.id";

    // "Available" = a customer could book it right now at checkout.
    private const AVAILABLE = 's.is_active = 1 AND s.starts_at > UTC_TIMESTAMP() AND s.capacity > COALESCE(b.booked, 0)';

    public function __construct(private PDO $pdo)
    {
    }

    public function search(?string $date, ?bool $available): array
    {
        $where = [];
        $params = [];

        if ($date !== null) {
            // A range instead of DATE(starts_at) = :day keeps the starts_at index usable.
            $where[] = 's.starts_at >= :day_start AND s.starts_at < :day_end';
            $params[':day_start'] = $date . ' 00:00:00';
            $params[':day_end'] = (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))
                ->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if ($available === true) {
            $where[] = self::AVAILABLE;
        } elseif ($available === false) {
            $where[] = 'NOT (' . self::AVAILABLE . ')';
        }

        $sql = self::SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY s.starts_at, s.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map([$this, 'toSlot'], $stmt->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE s.id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $this->toSlot($row);
    }

    public function lock(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT starts_at, ends_at, capacity, is_active FROM delivery_slots WHERE id = :id FOR UPDATE'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['capacity'] = (int) $row['capacity'];
        $row['is_active'] = (bool) $row['is_active'];
        return $row;
    }

    public function countBookings(int $id): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM orders
             WHERE delivery_slot_id = :id AND status <> 'cancelled' LOCK IN SHARE MODE"
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** Any order at all even cancelled or delivered keeps its slot as history. */
    public function hasOrders(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM orders WHERE delivery_slot_id = :id LIMIT 1 LOCK IN SHARE MODE');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    public function create(array $slot): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO delivery_slots (starts_at, ends_at, capacity, is_active)
             VALUES (:starts_at, :ends_at, :capacity, :is_active)'
        );
        $this->bindSlot($stmt, $slot);
        $stmt->execute();

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $slot): array
    {
        $stmt = $this->pdo->prepare(
            'UPDATE delivery_slots
                SET starts_at = :starts_at, ends_at = :ends_at, capacity = :capacity, is_active = :is_active
              WHERE id = :id'
        );
        $this->bindSlot($stmt, $slot);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM delivery_slots WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function bindSlot(\PDOStatement $stmt, array $slot): void
    {
        $stmt->bindValue(':starts_at', $slot['startsAt']);
        $stmt->bindValue(':ends_at', $slot['endsAt']);
        $stmt->bindValue(':capacity', $slot['capacity'], PDO::PARAM_INT);
        $stmt->bindValue(':is_active', $slot['isActive'] ? 1 : 0, PDO::PARAM_INT);
    }

    /** Maps a DB row to the documented public DeliverySlot shape. */
    private function toSlot(array $row): array
    {
        $capacity = (int) $row['capacity'];
        $booked = (int) $row['booked'];
        $remaining = max($capacity - $booked, 0);
        $active = (bool) $row['is_active'];

        return [
            'id' => (int) $row['id'],
            'startsAt' => self::utc($row['starts_at']),
            'endsAt' => self::utc($row['ends_at']),
            'capacity' => $capacity,
            'bookedCount' => $booked,
            'availableCapacity' => $remaining,
            'isAvailable' => $active && (bool) $row['is_future'] && $remaining > 0,
            'isActive' => $active,
            'createdAt' => $row['created_at'],
            'updatedAt' => $row['updated_at'],
        ];
    }

    private static function utc(string $value): string
    {
        return str_replace(' ', 'T', $value) . 'Z';
    }
}

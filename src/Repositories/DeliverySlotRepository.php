<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

/**
 * All the SQL for delivery slots
 * The controller calls these methods and turns the results into JSON.
 */
final class DeliverySlotRepository
{
    // The base query every read uses. For each slot it also works out:
    //   booked: how many non-cancelled orders use it (0 if none)
    //   is_future: 1 if the slot hasn't started yet, otherwise 0
    //
    // The inner SELECT counts the orders of ALL slots in one pass
    // (GROUP BY), and the LEFT JOIN attaches each count to its slot."LEFT" JOIN keeps
    // slots that have no orders at all; COALESCE turns their missing count into 0.
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

    // "Available" means a customer could pick this slot at checkout RIGHT NOW:
    private const AVAILABLE = 's.is_active = 1 AND s.starts_at > UTC_TIMESTAMP() AND s.capacity > COALESCE(b.booked, 0)';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Lists slots, ordered by when they start, with optional filters:
     *   $date      'Y-m-d' = only slots that START on that UTC day; null = any day
     *   $available true = only bookable slots, false = only non-bookable ones,
     *              null = don't filter
     * Both filters can be used together (they're joined with AND).
     */
    public function search(?string $date, ?bool $available): array
    {
        $where = [];
        $params = [];

        if ($date !== null) {
            // "Starts on Nov 20" is written as a range:
            //     starts_at >= '2026-11-20 00:00:00' AND starts_at < '2026-11-21 00:00:00'
            // It needs two different placeholders (:day_start and :day_end):
            // with real prepared statements, PDO doesn't allow using the same
            // named placeholder twice in one query.
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

    /**
     * One slot by id, in the public API shape, or null if it doesn't exist.
     *
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE s.id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $this->toSlot($row);
    }

    /**
     * Reads a slot AND locks its row until the current transaction ends.
     * Returns null if the slot doesn't exist.
     *
     * (Write routes run inside the transaction that Authorization::protect()
     * opens, so the lock lasts until our response is done.)
     *
     * @return array{starts_at:string,ends_at:string,capacity:int,is_active:bool}|null
     */
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

    /**
     * How many non-cancelled orders use this slot right now.
     * 
     * Adding LOCK IN SHARE MODE makes MySQL read the latest saved
     * orders instead, so the count is up to date.
     */
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

    /**
     * True if ANY order uses this slot, including cancelled and delivered ones.
     * Those orders are history and must keep pointing at their slot, so a
     * slot with any order at all can't be deleted.
     */
    public function hasOrders(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM orders WHERE delivery_slot_id = :id LIMIT 1 LOCK IN SHARE MODE');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Saves a new slot and returns it read back from the database (so the
     * response shows exactly what was stored, including id and createdAt).
     *
     * @param array{startsAt:string,endsAt:string,capacity:int,isActive:bool} $slot times already in UTC
     */
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

    /**
     * Overwrites a slot's values and returns the updated slot.
     * The controller has already checked the booking rules before calling this.
     *
     * @param array{startsAt:string,endsAt:string,capacity:int,isActive:bool} $slot times already in UTC
     */
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

    /** Deletes the slot. The controller makes sure no order uses it first. */
    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM delivery_slots WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Fills in the 4 placeholders that create() and update() share. */
    private function bindSlot(\PDOStatement $stmt, array $slot): void
    {
        $stmt->bindValue(':starts_at', $slot['startsAt']);
        $stmt->bindValue(':ends_at', $slot['endsAt']);
        $stmt->bindValue(':capacity', $slot['capacity'], PDO::PARAM_INT);
        $stmt->bindValue(':is_active', $slot['isActive'] ? 1 : 0, PDO::PARAM_INT);   // MySQL stores booleans as 1/0
    }

    /**
     * Turns one database row into the JSON the API documents
     */
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

    /**
     * Database format -> API format:
     * '2026-11-20 19:00:00' becomes '2026-11-20T19:00:00Z' (the Z means UTC).
     */
    private static function utc(string $value): string
    {
        return str_replace(' ', 'T', $value) . 'Z';
    }
}

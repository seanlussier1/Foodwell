<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;

final class DeliveryOptionRepository
{
    public function __construct(private PDO $pdo) {}

    public function available(): array
    {
        $rows = $this->pdo->query(
            "SELECT s.id, DATE_FORMAT(s.starts_at, '%Y-%m-%dT%H:%i:%sZ') AS starts_at,
                    DATE_FORMAT(s.ends_at, '%Y-%m-%dT%H:%i:%sZ') AS ends_at,
                    s.capacity - COALESCE(b.booked, 0) AS remaining
             FROM delivery_slots s LEFT JOIN (
                SELECT delivery_slot_id, COUNT(*) AS booked FROM orders
                WHERE status <> 'cancelled' AND delivery_slot_id IS NOT NULL GROUP BY delivery_slot_id
             ) b ON b.delivery_slot_id = s.id
             WHERE s.is_active = 1 AND s.starts_at > UTC_TIMESTAMP() AND s.capacity > COALESCE(b.booked, 0)
             ORDER BY s.starts_at, s.id LIMIT 10"
        )->fetchAll();
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'startsAt' => $row['starts_at'],
            'endsAt' => $row['ends_at'], 'remainingCapacity' => (int) $row['remaining']], $rows);
    }
}

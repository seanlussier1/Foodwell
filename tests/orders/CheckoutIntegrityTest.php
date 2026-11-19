<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CheckoutIntegrityTest extends TestCase
{
    private PDO $db;
    private array $ports;
    private array $tokens = [];

    protected function setUp(): void
    {
        $name = getenv('DB_NAME');
        $ports = getenv('FOODWELL_ORDER_TEST_PORTS');
        if (!is_string($name) || !preg_match('/^foodwell_newman_[a-f0-9]{12}$/D', $name)
            || !is_string($ports) || !preg_match('/^\d+,\d+$/D', $ports)) {
            throw new RuntimeException('Run with composer test:orders to provision the disposable fixture.');
        }
        $this->ports = explode(',', $ports);
        $this->db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), $name),
            getenv('DB_USER'),
            getenv('DB_PASS') ?: '',
            [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $root = dirname(__DIR__, 2);
        foreach (['schema', 'seed'] as $fixture) {
            $this->db->exec(file_get_contents("$root/database/$fixture.sql"));
        }
        foreach (['alex', 'sam', 'staff', 'admin'] as $account) {
            $this->tokens[$account] = $this->request(201, 'POST', '/auth/sessions', null, [
                'email' => "$account@example.com", 'password' => 'FoodwellDemo!2026',
            ])['accessToken'];
        }
    }

    public function testCheckoutRejectsEmptyCartStockCapacityAndUnauthorizedRequests(): void
    {
        $this->unchanged(409, 'POST', '/orders', 'sam', $this->checkout(8), 'cart is empty');
        $this->db->exec('UPDATE products SET stock = 1 WHERE id = 101');
        $this->unchanged(409, 'POST', '/orders', 'alex', $this->checkout(), 'Insufficient stock');
        $this->db->exec('UPDATE products SET stock = 42 WHERE id = 101');
        $this->unchanged(409, 'POST', '/orders', 'alex', $this->checkout(7, 301), 'slot is unavailable');
        $this->unchanged(403, 'POST', '/orders', 'alex', $this->checkout(8));
        $this->unchanged(403, 'POST', '/orders', 'staff', $this->checkout(9));
        $this->unchanged(403, 'GET', '/orders/901', 'alex');
        $this->unchanged(403, 'GET', '/orders/900', 'staff');
        $this->unchanged(403, 'GET', '/customers/8/orders', 'alex');
        $this->unchanged(403, 'GET', '/orders/901/status-events', 'alex');
        $this->unchanged(403, 'PATCH', '/orders/900', 'alex', ['status' => 'cancelled']);
        $this->unchanged(409, 'PATCH', '/orders/900', 'staff', ['status' => 'delivered']);
    }

    public function testRollbackDuplicateCheckoutAndCancellationRetry(): void
    {
        // Synthetic trigger fails after order/status and inventory writes.
        $this->withFailingEvent(fn () => $this->unchanged(500, 'POST', '/orders', 'alex', $this->checkout()));
        $checkout = ['POST', '/orders', 'alex', $this->checkout()];
        $results = $this->race('customers', 7, [$checkout, $checkout]);
        usort($results, static fn ($a, $b) => $a[0] <=> $b[0]);
        self::assertSame([201, 409], array_column($results, 0));
        self::assertStringContainsString('cart is empty', $results[1][1]['error']['message']);
        $order = $results[0][1];
        $id = $order['id'];
        self::assertEqualsWithDelta(12.97, $order['total'], 0.00001);
        self::assertSame('12.97', $this->scalar("SELECT total FROM orders WHERE id = $id"));
        self::assertSame(6, (int) $this->scalar('SELECT COUNT(*) FROM orders'));
        self::assertSame([40, 119], $this->stock(101, 104));
        self::assertSame(1, $this->bookings(304));
        self::assertSame(0, $this->cartLines(30));
        self::assertSame([2, 1], array_map('intval', $this->db->query(
            "SELECT quantity FROM order_items WHERE order_id = $id ORDER BY product_id"
        )->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(['pending'], $this->events($id));
        $this->unchanged(409, 'POST', '/orders', 'alex', $this->checkout(), 'cart is empty');

        $cancel = ['status' => 'cancelled'];
        $this->withFailingEvent(fn () => $this->unchanged(500, 'PATCH', "/orders/$id", 'staff', $cancel));
        $results = $this->race('products', 101, [
            ['PATCH', "/orders/$id", 'staff', $cancel], ['PATCH', "/orders/$id", 'admin', $cancel],
        ]);
        self::assertSame([200, 200], array_column($results, 0));
        self::assertSame(['cancelled', 'cancelled'], array_column(array_column($results, 1), 'status'));
        self::assertSame([42, 120], $this->stock(101, 104));
        self::assertSame(0, $this->bookings(304));
        self::assertSame(0, $this->cartLines(30));
        self::assertSame(['pending', 'cancelled'], $this->events($id));
        self::assertContains((int) $this->scalar(
            "SELECT changed_by FROM order_status_events WHERE order_id = $id AND new_status = 'cancelled'"
        ), [9, 10]);
        $this->unchanged(200, 'PATCH', "/orders/$id", 'staff', $cancel);
        $this->unchanged(409, 'PATCH', "/orders/$id", 'staff', ['status' => 'packed']);
    }

    public function testCompetingSlotRequestsRetainLosingCartForRetry(): void
    {
        $this->db->exec('UPDATE delivery_slots SET capacity = 1 WHERE id = 304');
        $this->db->exec('INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (31, 102, 1)');
        $this->db->exec('INSERT INTO delivery_slots (id, starts_at, ends_at, capacity) VALUES
            (305, UTC_TIMESTAMP() + INTERVAL 20 DAY, UTC_TIMESTAMP() + INTERVAL 20 DAY + INTERVAL 1 HOUR, 1)');
        $results = $this->race('delivery_slots', 304, [
            ['POST', '/orders', 'alex', $this->checkout()], ['POST', '/orders', 'sam', $this->checkout(8)],
        ]);
        $statuses = array_column($results, 0);
        sort($statuses);
        self::assertSame([201, 409], $statuses);
        $winner = $results[0][0] === 201 ? 0 : 1;
        $loser = 1 - $winner;
        self::assertSame(1, $this->bookings(304));
        self::assertSame(0, $this->cartLines(30 + $winner));
        self::assertSame($loser === 0 ? 2 : 1, $this->cartLines(30 + $loser));
        self::assertSame($winner === 0 ? [40, 25, 119] : [42, 24, 120], $this->stock(101, 102, 104));
        self::assertSame(6, (int) $this->scalar('SELECT COUNT(*) FROM orders'));
        self::assertSame(['pending'], $this->events($results[$winner][1]['id']));
        $actor = ['alex', 'sam'][$loser];
        $this->unchanged(409, 'POST', '/orders', $actor, $this->checkout(7 + $loser), 'slot is unavailable');
        $retry = $this->request(201, 'POST', '/orders', $actor, $this->checkout(7 + $loser, 305));
        self::assertSame(['pending'], $this->events($retry['id']));
        self::assertSame(1, $this->bookings(305));
        self::assertSame([40, 24, 119], $this->stock(101, 102, 104));
        self::assertSame(0, $this->cartLines(30 + $loser));
    }

    private function withFailingEvent(callable $check): void
    {
        $this->db->exec("CREATE TRIGGER order_test_failure BEFORE INSERT ON order_status_events FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic rollback fixture'");
        try {
            $check();
        } finally {
            $this->db->exec('DROP TRIGGER order_test_failure');
        }
    }

    private function checkout(int $customer = 7, int $slot = 304): array
    {
        return ['customerId' => $customer, 'deliverySlotId' => $slot, 'deliveryAddress' => '123 Fixture Street'];
    }

    private function scalar(string $sql): mixed
    {
        return $this->db->query($sql)->fetchColumn();
    }

    private function stock(int ...$products): array
    {
        return array_map(fn ($id) => (int) $this->scalar("SELECT stock FROM products WHERE id = $id"), $products);
    }

    private function bookings(int $slot): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM orders WHERE delivery_slot_id = $slot AND status <> 'cancelled'");
    }

    private function cartLines(int $cart): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM cart_items WHERE cart_id = $cart");
    }

    private function events(int $order): array
    {
        return $this->db->query(
            "SELECT new_status FROM order_status_events WHERE order_id = $order ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    private function snapshot(): array
    {
        $tables = ['products', 'delivery_slots', 'carts', 'cart_items', 'orders', 'order_items', 'order_status_events'];
        return array_map(fn ($table) => $this->db->query("SELECT * FROM $table ORDER BY id")->fetchAll(), $tables);
    }

    private function unchanged(int $status, string $method, string $path, string $actor, ?array $body = null, ?string $message = null): void
    {
        $before = $this->snapshot();
        $result = $this->request($status, $method, $path, $actor, $body);
        if ($message !== null) {
            self::assertStringContainsString($message, $result['error']['message']);
        }
        self::assertSame($before, $this->snapshot());
    }

    private function request(int $status, string $method, string $path, ?string $actor, ?array $body = null): array
    {
        $curl = $this->handle(0, $method, $path, $actor, $body);
        $response = curl_exec($curl);
        self::assertSame($status, curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response ?: curl_error($curl));
        return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    }

    private function handle(int $worker, string $method, string $path, ?string $actor, ?array $body = null): CurlHandle
    {
        $headers = ['Content-Type: application/json'];
        if ($actor !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->tokens[$actor];
        }
        $curl = curl_init("http://127.0.0.1:{$this->ports[$worker]}/api/v1$path");
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        return $curl;
    }

    private function race(string $table, int $id, array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT id FROM $table WHERE id = $id FOR UPDATE")->closeCursor();
            foreach ($requests as $worker => $input) {
                $curl = $this->handle($worker, ...$input);
                curl_multi_add_handle($multi, $curl);
                $handles[] = $curl;
            }
            $deadline = microtime(true) + 5;
            do {
                curl_multi_exec($multi, $running);
                $waiters = (int) $this->scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST
                    WHERE DB = DATABASE() AND ID <> CONNECTION_ID() AND INFO LIKE '%FOR UPDATE%'");
                if ($waiters < 2) {
                    usleep(150000);
                }
            } while ($waiters < 2 && $running === 2 && microtime(true) < $deadline);
            self::assertSame(2, $waiters, 'Both HTTP workers reached a locking read before the test released the row.');
            $this->db->commit();
            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running);
            return array_map(static fn (CurlHandle $curl) => [
                curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                json_decode(curl_multi_getcontent($curl), true, flags: JSON_THROW_ON_ERROR),
            ], $handles);
        } finally {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            foreach ($handles as $curl) {
                curl_multi_remove_handle($multi, $curl);
            }
        }
    }
}

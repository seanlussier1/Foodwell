<?php
declare(strict_types=1);

use Foodwell\Repositories\CategoryRepository;
use Foodwell\Repositories\DeliverySlotRepository;
use Foodwell\Repositories\ProductRepository;
use PHPUnit\Framework\TestCase;

/**
 * Repository tests for the catalog and delivery slots (#42).
 *
 * The repositories are the classes that talk to the database. Their SQL uses
 * MySQL/MariaDB-only features (FOR UPDATE, LOCK IN SHARE MODE, UTC_TIMESTAMP()),
 * so a fake database wouldn't prove much. These tests run against a REAL,
 * throwaway MariaDB database instead.
 *
 * Don't run this file on its own. Run:
 *
 *   composer test:catalog
 *
 * That command (scripts/check-newman.php --catalog) creates a disposable
 * database named foodwell_newman_<random>, points DB_NAME at it, runs this
 * file, then runs the Newman collection, then deletes the database. setUp()
 * refuses to run against anything else, so it can never touch your real data.
 *
 * Before EVERY test, the schema and seed data are loaded again, so each test
 * starts from the same known state and tests can't affect each other.
 */
final class CatalogRepositoryTest extends TestCase
{
    private PDO $db;
    private ProductRepository $products;
    private CategoryRepository $categories;
    private DeliverySlotRepository $slots;

    protected function setUp(): void
    {
        // Safety check: only ever run inside the disposable test database.
        $name = getenv('DB_NAME');
        if (!is_string($name) || preg_match('/^foodwell_newman_[a-f0-9]{12}$/D', $name) !== 1) {
            throw new RuntimeException('Run with composer test:catalog to provision the disposable database.');
        }

        // The same connection settings as src/Database.php, so the
        // repositories behave exactly as they do in the real app.
        $this->db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), $name),
            (string) getenv('DB_USER'),
            getenv('DB_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false]
        );
        $root = dirname(__DIR__, 2);
        foreach (['schema', 'seed'] as $fixture) {
            $this->db->exec(file_get_contents("$root/database/$fixture.sql"));
        }

        $this->products = new ProductRepository($this->db);
        $this->categories = new CategoryRepository($this->db);
        $this->slots = new DeliverySlotRepository($this->db);
    }

    // ------------------------------------------------------------ products

    public function testProductSearchFiltersAndCountsBeforePaging(): void
    {
        // Category 2 (Dairy) has 3 products. With limit 2, the first page has
        // 2 items but "total" must still say 3, so the client knows there is a page 2.
        $page = $this->products->search(2, null, null, 2, 0);

        self::assertSame(3, $page['total']);
        self::assertSame([101, 102], array_column($page['items'], 'id'));
        self::assertSame([103], array_column($this->products->search(2, null, null, 2, 2)['items'], 'id'));

        // Search is case-insensitive and combines with the category (AND).
        self::assertSame([102], array_column($this->products->search(2, 'CHEDDAR', null, 10, 0)['items'], 'id'));
        self::assertSame(0, $this->products->search(1, 'cheddar', null, 10, 0)['total']);
    }

    public function testProductSortsUseTheIdAsATieBreaker(): void
    {
        // Two more products at 2.99 (Gala Apples, id 104, already costs 2.99).
        // When prices tie, the id decides the order, so paging is stable.
        $this->db->exec("INSERT INTO products (id, name, price, category_id) VALUES (111, 'Pears', 2.99, 1), (112, 'Plums', 2.99, 1)");

        $ascending = array_column($this->products->search(null, null, 'price', 100, 0)['items'], 'id');
        $descending = array_column($this->products->search(null, null, '-price', 100, 0)['items'], 'id');

        $tied = array_values(array_intersect($ascending, [104, 111, 112]));
        self::assertSame([104, 111, 112], $tied, 'price: ties in id order');
        self::assertSame([112, 111, 104], array_values(array_intersect($descending, [104, 111, 112])), '-price: ties in reverse id order');
        self::assertSame(array_reverse($ascending), $descending, '-price is exactly price reversed');
    }

    public function testProductSearchTreatsWildcardsAsNormalCharacters(): void
    {
        // In SQL LIKE, % means "anything" and _ means "any one character".
        // The repository escapes them, so a search for "%" finds a literal %.
        $this->db->exec("INSERT INTO products (id, name, price, category_id) VALUES (111, '100% Juice', 3.25, 4), (112, 'snake_case', 1.00, 4)");

        self::assertSame([111], array_column($this->products->search(null, '%', null, 10, 0)['items'], 'id'));
        self::assertSame([112], array_column($this->products->search(null, '_', null, 10, 0)['items'], 'id'));
        self::assertSame(0, $this->products->search(null, '\\', null, 10, 0)['total']);
    }

    public function testProductCreateUpdateAndDeleteRoundTrip(): void
    {
        $created = $this->products->create(['name' => 'Oat Milk', 'price' => 3.49, 'unit' => 'each', 'stock' => 7,
            'categoryId' => 2, 'imageUrl' => null]);

        // The repository reads the row back, so the result is what the database really stored.
        self::assertSame(['name' => 'Oat Milk', 'price' => 3.49, 'unit' => 'each', 'stock' => 7, 'categoryId' => 2,
            'categoryName' => 'Dairy', 'imageUrl' => null, 'isActive' => true],
            array_diff_key($created, ['id' => true]));

        $updated = $this->products->update($created['id'], ['name' => 'Oat Milk 2L', 'price' => 5.99, 'unit' => 'each',
            'categoryId' => 4, 'imageUrl' => 'https://example.com/oat.jpg', 'isActive' => false]);
        self::assertSame(['Oat Milk 2L', 5.99, 'Pantry', false, 7],
            [$updated['name'], $updated['price'], $updated['categoryName'], $updated['isActive'], $updated['stock']],
            'Update changes the fields but never the stock');

        $this->products->delete($created['id']);
        self::assertNull($this->products->findById($created['id']));
    }

    public function testProductHelperChecks(): void
    {
        self::assertTrue($this->products->categoryExists(1));
        self::assertFalse($this->products->categoryExists(999));
        self::assertTrue($this->products->hasInventoryHistory(109), 'The seed has a restock for product 109');
        self::assertFalse($this->products->hasInventoryHistory(101));
        self::assertNull($this->products->findById(999));
    }

    public function testTheDatabaseRefusesAProductInAMissingCategory(): void
    {
        // The controller checks categoryExists() first, but the foreign key is
        // the final safety net: MySQL error 1452 ("parent row missing").
        try {
            $this->products->create(['name' => 'Ghost', 'price' => 1.0, 'unit' => 'each', 'stock' => 0,
                'categoryId' => 999, 'imageUrl' => null]);
            self::fail('The insert should have been refused');
        } catch (PDOException $e) {
            self::assertSame(1452, $e->errorInfo[1]);
        }
    }

    public function testTheDatabaseRefusesDeletingAProductWithInventoryHistory(): void
    {
        // Error 1451 = "row is still referenced". The controller turns it into a 409.
        try {
            $this->products->delete(109);
            self::fail('The delete should have been refused');
        } catch (PDOException $e) {
            self::assertSame(1451, $e->errorInfo[1]);
        }
        self::assertNotNull($this->products->findById(109));
    }

    // ------------------------------------------------------------ categories

    public function testCategoriesAreListedInIdOrder(): void
    {
        self::assertSame([[ 'id' => 1, 'name' => 'Produce'], ['id' => 2, 'name' => 'Dairy'],
            ['id' => 3, 'name' => 'Bakery'], ['id' => 4, 'name' => 'Pantry']], $this->categories->findAll());
        self::assertSame(['id' => 3, 'name' => 'Bakery'], $this->categories->findById(3));
        self::assertNull($this->categories->findById(999));
    }

    public function testCategoryNamesAreUniqueIgnoringCase(): void
    {
        $created = $this->categories->create('Frozen');
        self::assertSame('Frozen', $this->categories->findById($created['id'])['name']);

        // The column's collation ignores case, so "FROZEN" counts as the same
        // name: MySQL error 1062 ("duplicate entry"). The controller sends 409.
        try {
            $this->categories->create('FROZEN');
            self::fail('A duplicate name should have been refused');
        } catch (PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
    }

    public function testCategoryDeleteHasThreeOutcomes(): void
    {
        // delete() returns null (not found), ['inUse' => true] or the deleted category.
        self::assertNull($this->categories->delete(999));
        self::assertSame(['inUse' => true], $this->categories->delete(1));
        self::assertNotNull($this->categories->findById(1), 'A category in use is kept');

        $empty = $this->categories->create('Seasonal');
        self::assertSame($empty, $this->categories->delete($empty['id']));
        self::assertNull($this->categories->findById($empty['id']));
    }

    public function testCategoryDeleteWorksInsideAnOuterTransaction(): void
    {
        // Real requests run inside Authorization::protect()'s transaction.
        // delete() must not start (or commit) a second one in that case;
        // only the outer transaction decides.
        $empty = $this->categories->create('Seasonal');
        $this->db->beginTransaction();
        self::assertSame($empty, $this->categories->delete($empty['id']));
        self::assertTrue($this->db->inTransaction(), 'delete() left the outer transaction open');
        $this->db->rollBack();

        self::assertNotNull($this->categories->findById($empty['id']), 'Rolling back the outer transaction undoes the delete');
    }

    // ------------------------------------------------------------ delivery slots

    public function testSlotBookingsCountOnlyNonCancelledOrders(): void
    {
        // Seed: slot 301 has 2 orders, slot 302 has 1, both full.
        $slot = $this->slots->findById(301);
        self::assertSame([2, 2, 0, false], [$slot['capacity'], $slot['bookedCount'], $slot['availableCapacity'], $slot['isAvailable']]);

        // Cancelling one order gives its place back.
        $order = (int) $this->db->query('SELECT id FROM orders WHERE delivery_slot_id = 301 ORDER BY id LIMIT 1')->fetchColumn();
        $this->db->exec("UPDATE orders SET status = 'cancelled' WHERE id = $order");
        $slot = $this->slots->findById(301);
        self::assertSame([1, 1, true], [$slot['bookedCount'], $slot['availableCapacity'], $slot['isAvailable']]);
        self::assertSame(1, $this->slots->countBookings(301));
        self::assertTrue($this->slots->hasOrders(301), 'A cancelled order still counts as history for delete');
    }

    public function testSlotSearchFilters(): void
    {
        // Seed: 303 is in the past (inactive), 301 and 302 are full, only 304 is bookable.
        self::assertSame([303, 301, 302, 304], array_column($this->slots->search(null, null), 'id'), 'All slots, by start time');
        self::assertSame([304], array_column($this->slots->search(null, true), 'id'));
        self::assertSame([303, 301, 302], array_column($this->slots->search(null, false), 'id'));

        $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format('Y-m-d');
        self::assertSame([301], array_column($this->slots->search($tomorrow, null), 'id'), 'date = the UTC day the slot starts');
        self::assertSame([], $this->slots->search($tomorrow, true), 'date AND available');
    }

    public function testSlotTimesAreReturnedAsUtc(): void
    {
        $created = $this->slots->create(['startsAt' => '2027-01-15 19:00:00', 'endsAt' => '2027-01-15 21:00:00',
            'capacity' => 3, 'isActive' => true]);

        self::assertSame('2027-01-15T19:00:00Z', $created['startsAt']);
        self::assertSame('2027-01-15T21:00:00Z', $created['endsAt']);
        self::assertSame([3, 0, 3, true], [$created['capacity'], $created['bookedCount'], $created['availableCapacity'], $created['isAvailable']]);
    }

    public function testSlotUpdateDeleteAndDuplicateWindow(): void
    {
        $window = ['startsAt' => '2027-01-15 19:00:00', 'endsAt' => '2027-01-15 21:00:00'];
        $created = $this->slots->create($window + ['capacity' => 3, 'isActive' => true]);

        $updated = $this->slots->update($created['id'], $window + ['capacity' => 5, 'isActive' => false]);
        self::assertSame([5, false, false], [$updated['capacity'], $updated['isActive'], $updated['isAvailable']]);

        // Two slots can't share the exact same window (error 1062; the controller sends 409).
        try {
            $this->slots->create($window + ['capacity' => 1, 'isActive' => true]);
            self::fail('A duplicate window should have been refused');
        } catch (PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }

        $this->slots->delete($created['id']);
        self::assertNull($this->slots->findById($created['id']));
    }

    public function testSlotLockReturnsTheRawRowAndHoldsIt(): void
    {
        // lock() returns database-format values (used by the controller to
        // compare windows) and returns null for a missing slot.
        $this->db->beginTransaction();
        $row = $this->slots->lock(304);
        self::assertSame(['capacity' => 3, 'is_active' => true], array_intersect_key($row, ['capacity' => 0, 'is_active' => 0]));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['starts_at']);
        self::assertNull($this->slots->lock(999));

        // While we hold the lock, another connection can't lock the same row.
        // (innodb_lock_wait_timeout = 1 makes it give up after 1 second.)
        $other = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_NAME')),
            (string) getenv('DB_USER'), getenv('DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        try {
            $other->query('SELECT id FROM delivery_slots WHERE id = 304 FOR UPDATE');
            self::fail('The second connection should have waited for the lock');
        } catch (PDOException $e) {
            self::assertSame(1205, $e->errorInfo[1], 'MySQL 1205 = lock wait timeout');
        } finally {
            $other->rollBack();
            $this->db->rollBack();
        }
    }

    public function testTheDatabaseRefusesDeletingASlotUsedByAnOrder(): void
    {
        try {
            $this->slots->delete(301);
            self::fail('The delete should have been refused');
        } catch (PDOException $e) {
            self::assertSame(1451, $e->errorInfo[1]);
        }
        self::assertNotNull($this->slots->findById(301));
    }
}

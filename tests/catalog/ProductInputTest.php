<?php
declare(strict_types=1);

use Foodwell\Validation\ProductInput;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProductInput, the class that checks a product's JSON body
 * before anything reaches the database (#42).
 *
 * "Unit" means no database and no HTTP server: we call
 * ProductInput::validate() directly with a PHP array and look at what comes
 * back. That's why these run in a fraction of a second with
 * `composer test:unit`.
 *
 * validate() returns ['data' => cleaned values, 'errors' => field => message].
 * The second argument says which operation it is:
 *   true  = creating (POST): stock allowed, isActive not allowed
 *   false = updating (PUT):  isActive allowed, stock not allowed
 */
final class ProductInputTest extends TestCase
{
    /** A body that passes. Each test changes one thing to see what breaks. */
    private static function valid(): array
    {
        return ['name' => 'Oat Milk', 'price' => 3.49, 'categoryId' => 2];
    }

    public function testAValidBodyPassesAndFillsInDefaults(): void
    {
        $result = ProductInput::validate(self::valid(), true);

        self::assertSame([], $result['errors']);
        // Fields the client left out get their defaults.
        self::assertSame(['name' => 'Oat Milk', 'price' => 3.49, 'unit' => 'each', 'stock' => 0,
            'categoryId' => 2, 'imageUrl' => null], $result['data']);
    }

    public function testTextIsTrimmedAndAWholeNumberPriceBecomesAFloat(): void
    {
        $result = ProductInput::validate(['name' => '  Oat Milk  ', 'unit' => ' kg ', 'price' => 3] + self::valid(), true);

        self::assertSame([], $result['errors']);
        self::assertSame('Oat Milk', $result['data']['name']);
        self::assertSame('kg', $result['data']['unit']);
        self::assertSame(3.0, $result['data']['price']);   // 3 (int) is stored as 3.0, matching DECIMAL(8,2)
    }

    public function testLimitsAreInclusive(): void
    {
        // Exactly AT each limit is allowed. One past it is tested below.
        $result = ProductInput::validate([
            'name' => str_repeat('é', 150),             // 150 characters (300 bytes): characters are counted
            'price' => 999999.99,
            'unit' => str_repeat('u', 30),
            'stock' => 1000000,
            'categoryId' => 4294967295,                 // the largest INT UNSIGNED
            'imageUrl' => 'https://example.com/' . str_repeat('a', 235),   // exactly 255 characters
        ], true);

        self::assertSame([], $result['errors']);
    }

    /**
     * Each row: a change to the valid body, and the ONE field that must be reported.
     *
     * @dataProvider invalidFields
     */
    public function testEachInvalidFieldIsReportedByName(array $change, string $field): void
    {
        $result = ProductInput::validate($change + self::valid(), true);

        // Only that field is reported. (If there are ANY errors, the controller
        // answers 422 and never uses 'data', so we don't check 'data' here.)
        self::assertSame([$field], array_keys($result['errors']));
    }

    public static function invalidFields(): array
    {
        return [
            'name missing (null)' => [['name' => null], 'name'],
            'name only spaces' => [['name' => '   '], 'name'],
            'name of 151 characters' => [['name' => str_repeat('n', 151)], 'name'],
            'name as a number' => [['name' => 42], 'name'],
            'price as text' => [['price' => '3.49'], 'price'],
            'price as true' => [['price' => true], 'price'],
            'negative price' => [['price' => -0.01], 'price'],
            'price over the maximum' => [['price' => 1000000], 'price'],
            'price with 3 decimals' => [['price' => 1.005], 'price'],
            'unit empty' => [['unit' => ''], 'unit'],
            'unit of 31 characters' => [['unit' => str_repeat('u', 31)], 'unit'],
            'stock negative' => [['stock' => -1], 'stock'],
            'stock 1.5' => [['stock' => 1.5], 'stock'],
            'stock over the maximum' => [['stock' => 1000001], 'stock'],
            'categoryId as text' => [['categoryId' => '2'], 'categoryId'],
            'categoryId 0' => [['categoryId' => 0], 'categoryId'],
            'categoryId too big' => [['categoryId' => 4294967296], 'categoryId'],
            'imageUrl not a URL' => [['imageUrl' => 'not a url'], 'imageUrl'],
            'imageUrl with ftp' => [['imageUrl' => 'ftp://example.com/a.jpg'], 'imageUrl'],
            'imageUrl with javascript' => [['imageUrl' => 'javascript:alert(1)'], 'imageUrl'],
            'imageUrl of 256 characters' => [['imageUrl' => 'https://example.com/' . str_repeat('a', 236)], 'imageUrl'],
            'an id in the body' => [['id' => 7], 'id'],
            'isActive on create' => [['isActive' => true], 'isActive'],
        ];
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        // The client should be able to fix everything in one try.
        $result = ProductInput::validate(['name' => '', 'price' => -1, 'categoryId' => 'x', 'color' => 'red'], true);

        $fields = array_keys($result['errors']);
        sort($fields);
        self::assertSame(['categoryId', 'color', 'name', 'price'], $fields);
    }

    public function testUpdateRefusesStockWithAPointerToInventoryAdjustments(): void
    {
        // Stock may only change through an inventory adjustment (so there is
        // a history of who changed it). PUT says so instead of a generic message.
        $result = ProductInput::validate(self::valid() + ['stock' => 5], false);

        self::assertSame(['stock'], array_keys($result['errors']));
        self::assertStringContainsString('inventory adjustment', $result['errors']['stock']);
    }

    public function testUpdateAcceptsIsActiveOnlyAsTrueOrFalse(): void
    {
        self::assertSame(false, ProductInput::validate(self::valid() + ['isActive' => false], false)['data']['isActive']);
        self::assertArrayNotHasKey('isActive', ProductInput::validate(self::valid(), false)['data'],
            'Left out = not in data, so the controller keeps the current value');
        self::assertSame(['isActive'], array_keys(ProductInput::validate(self::valid() + ['isActive' => 'no'], false)['errors']));
        self::assertSame(['isActive'], array_keys(ProductInput::validate(self::valid() + ['isActive' => 0], false)['errors']));
    }
}

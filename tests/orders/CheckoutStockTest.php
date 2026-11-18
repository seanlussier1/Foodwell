<?php
declare(strict_types=1);

use Foodwell\Http\OrderException;
use Foodwell\Repositories\OrderRepository;
use PHPUnit\Framework\TestCase;

final class CheckoutStockTest extends TestCase
{
    /** @dataProvider unfulfillableQuantities */
    public function testRejectsUnfulfillableLineBeforePreparingAnyWrites(int $quantity, int $stock): void
    {
        // Labeled PDO double for the validation branch. MariaDB checks cover the actual writes.
        $connection = $this->createMock(PDO::class);
        $connection->expects(self::once())->method('beginTransaction')->willReturn(true);
        $connection->expects(self::exactly(2))->method('inTransaction')->willReturnOnConsecutiveCalls(false, true);
        $connection->expects(self::once())->method('rollBack')->willReturn(true);
        $connection->expects(self::never())->method('commit');

        $customer = $this->createMock(PDOStatement::class);
        $customer->method('fetch')->willReturn(['role' => 'customer', 'is_active' => 1]);
        $cart = $this->createMock(PDOStatement::class);
        $cart->method('fetchColumn')->willReturn(30);
        $lines = $this->createMock(PDOStatement::class);
        $lines->method('fetchAll')->willReturn([['product_id' => 101, 'quantity' => $quantity]]);
        $product = $this->createMock(PDOStatement::class);
        $product->method('fetch')->willReturn(['is_active' => 1, 'stock' => $stock, 'name' => 'Fixture product']);
        $connection->expects(self::exactly(4))->method('prepare')->willReturnOnConsecutiveCalls($customer, $cart, $lines, $product);

        try {
            (new OrderRepository($connection))->create(7, '123 Synthetic Street');
            self::fail('Expected checkout to reject the fixture line.');
        } catch (OrderException $error) {
            self::assertSame(409, $error->statusCode);
            self::assertStringContainsString('Insufficient stock', $error->getMessage());
        }
    }

    public function unfulfillableQuantities(): array
    {
        return [
            'zero quantity' => [0, 42],
            'quantity exceeds current stock' => [2, 1],
        ];
    }
}

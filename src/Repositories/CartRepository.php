<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;
use Throwable;

final class CartRepository
{
    public function __construct(private PDO $pdo) {}

    public function get(int $customerId): array
    {
        return $this->withCart($customerId, false, fn(?int $cartId): array => $this->view($customerId, $cartId));
    }

    public function add(int $customerId, int $productId, int $quantity): array
    {
        return $this->withCart($customerId, true, function (int $cartId) use ($customerId, $productId, $quantity): array {
            $product = $this->lockProduct($productId);
            $item = $this->pdo->prepare('SELECT id, quantity FROM cart_items WHERE cart_id = :cart AND product_id = :product');
            $item->execute(['cart' => $cartId, 'product' => $productId]);
            $existing = $item->fetch();
            $newQuantity = $quantity + ($existing === false ? 0 : (int) $existing['quantity']);
            $this->checkStock($product, $newQuantity);
            if ($existing === false) {
                $write = $this->pdo->prepare('INSERT INTO cart_items(cart_id, product_id, quantity) VALUES (:cart, :product, :quantity)');
                $write->execute(['cart' => $cartId, 'product' => $productId, 'quantity' => $newQuantity]);
            } else {
                $write = $this->pdo->prepare('UPDATE cart_items SET quantity = :quantity WHERE id = :id AND cart_id = :cart');
                $write->execute(['quantity' => $newQuantity, 'id' => $existing['id'], 'cart' => $cartId]);
            }
            $this->touch($cartId);
            return ['created' => $existing === false, 'cart' => $this->view($customerId, $cartId)];
        });
    }

    public function update(int $customerId, int $itemId, int $quantity): array
    {
        return $this->withCart($customerId, false, function (?int $cartId) use ($customerId, $itemId, $quantity): array {
            $item = $this->item($cartId, $itemId);
            $this->checkStock($this->lockProduct((int) $item['product_id']), $quantity);
            $write = $this->pdo->prepare('UPDATE cart_items SET quantity = :quantity WHERE id = :id AND cart_id = :cart');
            $write->execute(['quantity' => $quantity, 'id' => $itemId, 'cart' => $cartId]);
            $this->touch($cartId);
            return $this->view($customerId, $cartId);
        });
    }

    public function remove(int $customerId, int $itemId): void
    {
        $this->withCart($customerId, false, function (?int $cartId) use ($itemId): void {
            $this->item($cartId, $itemId);
            $write = $this->pdo->prepare('DELETE FROM cart_items WHERE id = :id AND cart_id = :cart');
            $write->execute(['id' => $itemId, 'cart' => $cartId]);
            $this->touch($cartId);
        });
    }

    /** Customer -> reusable cart -> products (ascending ID at checkout) -> item writes. */
    private function withCart(int $customerId, bool $create, callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            // This common first lock serializes cart edits, deactivation and checkout
            // when checkout adopts the same handoff protocol.
            $customer = $this->pdo->prepare('SELECT role, is_active FROM customers WHERE id = :id FOR UPDATE');
            $customer->execute(['id' => $customerId]);
            $account = $customer->fetch();
            if ($account === false) { throw new CartException(404, 'Customer not found.'); }
            if ($account['role'] !== 'customer' || !(bool) $account['is_active']) {
                throw new CartException(403, 'Cart access requires an active customer account.');
            }
            $cart = $this->pdo->prepare('SELECT id FROM carts WHERE customer_id = :id FOR UPDATE');
            $cart->execute(['id' => $customerId]);
            $value = $cart->fetchColumn();
            $cartId = $value === false ? null : (int) $value;
            if ($cartId === null && $create) {
                $insert = $this->pdo->prepare('INSERT INTO carts(customer_id) VALUES (:id)');
                $insert->execute(['id' => $customerId]);
                $cartId = (int) $this->pdo->lastInsertId();
            }
            $result = $operation($cartId);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }

    private function item(?int $cartId, int $itemId): array
    {
        $item = $this->pdo->prepare('SELECT product_id FROM cart_items WHERE cart_id = :cart AND id = :item');
        $item->execute(['cart' => $cartId, 'item' => $itemId]);
        $row = $item->fetch();
        if ($row === false) { throw new CartException(404, 'Cart item not found in this customer\'s cart.'); }
        return $row;
    }

    private function lockProduct(int $id): array
    {
        $product = $this->pdo->prepare('SELECT id, stock, is_active FROM products WHERE id = :id FOR UPDATE');
        $product->execute(['id' => $id]);
        $row = $product->fetch();
        if ($row === false) { throw new CartException(404, 'Product not found.'); }
        return $row;
    }

    private function checkStock(array $product, int $quantity): void
    {
        if (!(bool) $product['is_active']) { throw new CartException(409, 'This product is inactive.'); }
        if ($quantity > 1000000 || $quantity > (int) $product['stock']) {
            throw new CartException(409, 'Requested cart quantity exceeds available stock or the per-line limit.');
        }
    }

    private function touch(int $cartId): void
    {
        $touch = $this->pdo->prepare('UPDATE carts SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $touch->execute(['id' => $cartId]);
    }

    private function view(int $customerId, ?int $cartId): array
    {
        if ($cartId === null) {
            return ['id' => null, 'customerId' => $customerId, 'items' => [], 'subtotal' => 0, 'itemCount' => 0, 'totalQuantity' => 0];
        }
        $items = $this->pdo->prepare(
            'SELECT ci.id, ci.product_id, ci.quantity, p.name, p.unit, p.price, p.stock, p.is_active,
                    (p.price * ci.quantity) AS line_total
             FROM cart_items ci JOIN products p ON p.id = ci.product_id
             WHERE ci.cart_id = :id ORDER BY p.id ASC'
        );
        $items->execute(['id' => $cartId]);
        $data = [];
        $totalQuantity = 0;
        foreach ($items->fetchAll() as $row) {
            $totalQuantity += (int) $row['quantity'];
            $data[] = [
                'id' => (int) $row['id'], 'productId' => (int) $row['product_id'],
                'productName' => $row['name'], 'unit' => $row['unit'], 'quantity' => (int) $row['quantity'],
                'unitPrice' => (float) $row['price'], 'lineTotal' => (float) $row['line_total'],
                'availableStock' => (int) $row['stock'], 'isActive' => (bool) $row['is_active'],
            ];
        }
        // DECIMAL arithmetic stays in MariaDB, not an accumulation of PHP floats.
        $sum = $this->pdo->prepare('SELECT COALESCE(SUM(p.price * ci.quantity), 0) FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.cart_id = :id');
        $sum->execute(['id' => $cartId]);
        return ['id' => $cartId, 'customerId' => $customerId, 'items' => $data,
            'subtotal' => (float) $sum->fetchColumn(), 'itemCount' => count($data), 'totalQuantity' => $totalQuantity];
    }
}

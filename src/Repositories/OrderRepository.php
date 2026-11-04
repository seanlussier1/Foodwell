<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;
use Throwable;
use Foodwell\Http\OrderException;

final class OrderRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(
        int $customerId,
        ?string $deliveryAddress,
        ?int $deliverySlotId = null,
        ?int $addressId = null
    ): array {
        $this->pdo->beginTransaction();

        try {
            // Lock the customer first, following the cart transaction protocol.
            $customer = $this->pdo->prepare(
                'SELECT role, is_active
                 FROM customers
                 WHERE id = :id
                 FOR UPDATE'
            );
            $customer->execute(['id' => $customerId]);
            $account = $customer->fetch();

            if ($account === false) {
                throw new OrderException(404, 'Customer not found.');
            }

            if ($account['role'] !== 'customer' || !(bool) $account['is_active']) {
                throw new OrderException(403, 'Checkout requires an active customer account.');
            }

            // Resolve a saved address only when it belongs to this customer.
            if ($addressId !== null) {
                $addressQuery = $this->pdo->prepare(
                    'SELECT recipient_name, address_line1, address_line2,
                            city, province, postal_code, country_code
                     FROM customer_addresses
                     WHERE id = :address AND customer_id = :customer
                     FOR UPDATE'
                );
                $addressQuery->execute([
                    'address' => $addressId,
                    'customer' => $customerId,
                ]);
                $savedAddress = $addressQuery->fetch();

                if ($savedAddress === false) {
                    throw new OrderException(
                        404,
                        'Saved address not found for this customer.'
                    );
                }

                $addressParts = [
                    $savedAddress['recipient_name'],
                    $savedAddress['address_line1'],
                ];

                if (
                    $savedAddress['address_line2'] !== null
                    && trim($savedAddress['address_line2']) !== ''
                ) {
                    $addressParts[] = $savedAddress['address_line2'];
                }

                $addressParts[] = $savedAddress['city'];
                $addressParts[] = $savedAddress['province'];
                $addressParts[] = $savedAddress['postal_code'];
                $addressParts[] = $savedAddress['country_code'];

                $deliveryAddress = implode(', ', $addressParts);
            }

            if ($deliveryAddress === null || trim($deliveryAddress) === '') {
                throw new OrderException(422, 'A delivery address is required.');
            }

            $deliveryAddress = trim($deliveryAddress);

            // Lock the customer's reusable cart.
            $cart = $this->pdo->prepare(
                'SELECT id
                 FROM carts
                 WHERE customer_id = :customer
                 FOR UPDATE'
            );
            $cart->execute(['customer' => $customerId]);
            $cartId = $cart->fetchColumn();

            if ($cartId === false) {
                throw new OrderException(409, 'Cannot checkout because the cart is empty.');
            }

            $cartId = (int) $cartId;

            // Read cart items in product ID order.
            $itemsQuery = $this->pdo->prepare(
                'SELECT ci.product_id, ci.quantity
                 FROM cart_items ci
                 WHERE ci.cart_id = :cart
                 ORDER BY ci.product_id ASC'
            );
            $itemsQuery->execute(['cart' => $cartId]);
            $cartItems = $itemsQuery->fetchAll();

            if ($cartItems === []) {
                throw new OrderException(409, 'Cannot checkout because the cart is empty.');
            }

            // Lock and validate every product before making changes.
            $products = [];

            $productQuery = $this->pdo->prepare(
                'SELECT id, name, unit, price, stock, is_active
                 FROM products
                 WHERE id = :id
                 FOR UPDATE'
            );

            foreach ($cartItems as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                $productQuery->execute(['id' => $productId]);
                $product = $productQuery->fetch();

                if ($product === false) {
                    throw new OrderException(
                        409,
                        'A product in the cart no longer exists.'
                    );
                }

                if (!(bool) $product['is_active']) {
                    throw new OrderException(
                        409,
                        'A product in the cart is inactive.'
                    );
                }

                if ($quantity < 1 || $quantity > (int) $product['stock']) {
                    throw new OrderException(
                        409,
                        'Insufficient stock for product: ' . $product['name']
                    );
                }

                $products[$productId] = [
                    'id' => $productId,
                    'name' => $product['name'],
                    'unit' => $product['unit'],
                    'price' => $product['price'],
                    'quantity' => $quantity,
                ];
            }

            // Validate the optional delivery slot.
            if ($deliverySlotId !== null) {
                $slotQuery = $this->pdo->prepare(
                    'SELECT id, is_active
                     FROM delivery_slots
                     WHERE id = :id
                     FOR UPDATE'
                );
                $slotQuery->execute(['id' => $deliverySlotId]);
                $slot = $slotQuery->fetch();

                if ($slot === false || !(bool) $slot['is_active']) {
                    throw new OrderException(
                        409,
                        'The delivery slot is unavailable.'
                    );
                }
            }

            // Calculate the total using current database prices.
            $totalQuery = $this->pdo->prepare(
                'SELECT COALESCE(SUM(p.price * ci.quantity), 0)
                 FROM cart_items ci
                 JOIN products p ON p.id = ci.product_id
                 WHERE ci.cart_id = :cart'
            );
            $totalQuery->execute(['cart' => $cartId]);
            $total = $totalQuery->fetchColumn();

            // Create the order.
            $orderInsert = $this->pdo->prepare(
                'INSERT INTO orders
                    (customer_id, delivery_slot_id, status, total, delivery_address)
                 VALUES
                    (:customer, :slot, :status, :total, :address)'
            );

            $orderInsert->execute([
                'customer' => $customerId,
                'slot' => $deliverySlotId,
                'status' => 'pending',
                'total' => $total,
                'address' => $deliveryAddress,
            ]);

            $orderId = (int) $this->pdo->lastInsertId();

            // Save product details as order-line snapshots and decrease stock.
            $itemInsert = $this->pdo->prepare(
                'INSERT INTO order_items
                    (order_id, product_id, product_name, unit, quantity, unit_price)
                 VALUES
                    (:order, :product, :name, :unit, :quantity, :price)'
            );

            $stockUpdate = $this->pdo->prepare(
                'UPDATE products
                 SET stock = stock - :quantity
                 WHERE id = :id AND stock >= :minimumStock'
            );

            foreach ($products as $product) {
                $itemInsert->execute([
                    'order' => $orderId,
                    'product' => $product['id'],
                    'name' => $product['name'],
                    'unit' => $product['unit'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price'],
                ]);

                $stockUpdate->execute([
                    'quantity' => $product['quantity'],
                    'id' => $product['id'],
                    'minimumStock' => $product['quantity'],
                ]);

                if ($stockUpdate->rowCount() !== 1) {
                    throw new OrderException(
                        409,
                        'Stock changed during checkout. Please try again.'
                    );
                }
            }

            // Record the initial order status.
            $eventInsert = $this->pdo->prepare(
                'INSERT INTO order_status_events
                    (order_id, previous_status, new_status, changed_by, source)
                 VALUES
                    (:order, NULL, :status, :customer, :source)'
            );

            $eventInsert->execute([
                'order' => $orderId,
                'status' => 'pending',
                'customer' => $customerId,
                'source' => 'application',
            ]);

            // Clear the cart only after all order and stock changes succeed.
            $clearCart = $this->pdo->prepare(
                'DELETE FROM cart_items WHERE cart_id = :cart'
            );
            $clearCart->execute(['cart' => $cartId]);

            $this->pdo->commit();

            return $this->find($orderId);
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $error;
        }
    }

    public function find(int $orderId): array
    {
        $orderQuery = $this->pdo->prepare(
            'SELECT id, customer_id, delivery_slot_id, status,
                    total, delivery_address, created_at
             FROM orders
             WHERE id = :id'
        );
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch();

        if ($order === false) {
            throw new OrderException(404, 'Order not found.');
        }

        $itemsQuery = $this->pdo->prepare(
            'SELECT product_id, product_name, unit, quantity, unit_price
             FROM order_items
             WHERE order_id = :order
             ORDER BY id ASC'
        );
        $itemsQuery->execute(['order' => $orderId]);

        $items = [];

        foreach ($itemsQuery->fetchAll() as $item) {
            $items[] = [
                'productId' => $item['product_id'] === null
                    ? null
                    : (int) $item['product_id'],
                'productName' => $item['product_name'],
                'unit' => $item['unit'],
                'quantity' => (int) $item['quantity'],
                'unitPrice' => (float) $item['unit_price'],
            ];
        }

        return [
            'id' => (int) $order['id'],
            'customerId' => (int) $order['customer_id'],
            'deliverySlotId' => $order['delivery_slot_id'] === null
                ? null
                : (int) $order['delivery_slot_id'],
            'status' => $order['status'],
            'items' => $items,
            'total' => (float) $order['total'],
            'deliveryAddress' => $order['delivery_address'],
            'createdAt' => $order['created_at'],
        ];
    }
}
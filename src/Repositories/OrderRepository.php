<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use PDO;
use Throwable;
use Foodwell\Http\OrderException;

final class OrderRepository
{
    private const TRANSITIONS = [
        'pending' => ['packed', 'cancelled'],
        'packed' => ['out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function __construct(private PDO $pdo) {}

    public static function isStatus(string $status): bool
    {
        return isset(self::TRANSITIONS[$status]);
    }

    public function updateStatus(int $orderId, string $status, int $actorId): array
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $lookup = $this->pdo->prepare('SELECT delivery_slot_id FROM orders WHERE id = ?');
            $lookup->execute([$orderId]);
            $slotId = $lookup->fetchColumn();
            if ($slotId === false) {
                throw new OrderException(404, 'Order not found.');
            }

            // Match checkout's product -> slot -> order lock order.
            if ($status === 'cancelled') {
                $items = $this->pdo->prepare(
                    'SELECT product_id, quantity FROM order_items WHERE order_id = ?
                     ORDER BY product_id, id'
                );
                $items->execute([$orderId]);
                $lines = $items->fetchAll();
                $lockProduct = $this->pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
                $hasMissingProduct = false;
                foreach ($lines as $line) {
                    $lockProduct->execute([$line['product_id']]);
                    if ($lockProduct->fetchColumn() === false) {
                        $hasMissingProduct = true;
                    }
                }
            }
            if ($slotId !== null) {
                $lockSlot = $this->pdo->prepare('SELECT id FROM delivery_slots WHERE id = ? FOR UPDATE');
                $lockSlot->execute([$slotId]);
                $lockSlot->fetchColumn();
            }
            $current = $this->pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
            $current->execute([$orderId]);
            $previous = $current->fetchColumn();
            if ($previous === false) {
                throw new OrderException(404, 'Order not found.');
            }

            if ($previous !== $status) {
                if (!in_array($status, self::TRANSITIONS[$previous], true)) {
                    throw new OrderException(409, "Cannot change order status from $previous to $status.");
                }
                if ($status === 'cancelled') {
                    if ($hasMissingProduct) {
                        throw new OrderException(409, 'Stock cannot be restored for a deleted product.');
                    }
                    $restore = $this->pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
                    foreach ($lines as $line) {
                        $restore->execute([$line['quantity'], $line['product_id']]);
                    }
                }

                // Capacity is derived from non-cancelled orders under the slot lock.
                $update = $this->pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
                $update->execute([$status, $orderId]);
                $event = $this->pdo->prepare(
                    'INSERT INTO order_status_events (order_id, previous_status, new_status, changed_by)
                     VALUES (?, ?, ?, ?)'
                );
                $event->execute([$orderId, $previous, $status, $actorId]);
            }

            $order = $this->find($orderId);
            // An idempotent retry may have started before the other writer committed.
            $order['status'] = $status;
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $order;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function listForCustomer(int $customerId, ?string $status, int $page, int $limit): array
    {
        $customer = $this->pdo->prepare('SELECT id FROM customers WHERE id = ?');
        $customer->execute([$customerId]);
        if ($customer->fetchColumn() === false) {
            throw new OrderException(404, 'Customer not found.');
        }

        $sql = 'SELECT id, customer_id, delivery_slot_id, status, total, delivery_address, created_at
                FROM orders WHERE customer_id = :customer';
        if ($status !== null) {
            $sql .= ' AND status = :status';
        }
        $query = $this->pdo->prepare($sql . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset');
        $query->bindValue('customer', $customerId, PDO::PARAM_INT);
        if ($status !== null) {
            $query->bindValue('status', $status);
        }
        $query->bindValue('limit', $limit, PDO::PARAM_INT);
        $query->bindValue('offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $query->execute();

        return array_map($this->formatOrder(...), $query->fetchAll());
    }

    public function listStatusEvents(int $orderId, int $page, int $limit): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, previous_status, new_status, changed_by, source,
                    UNIX_TIMESTAMP(created_at) AS created_epoch
             FROM order_status_events WHERE order_id = :order
             ORDER BY created_at ASC, id ASC LIMIT :limit OFFSET :offset'
        );
        $query->bindValue('order', $orderId, PDO::PARAM_INT);
        $query->bindValue('limit', $limit, PDO::PARAM_INT);
        $query->bindValue('offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $query->execute();

        return array_map(static fn (array $event): array => [
            'id' => (int) $event['id'],
            'orderId' => $orderId,
            'previousStatus' => $event['previous_status'],
            'newStatus' => $event['new_status'],
            'changedBy' => $event['changed_by'] === null ? null : (int) $event['changed_by'],
            'source' => $event['source'],
            'createdAt' => gmdate(DATE_ATOM, (int) $event['created_epoch']),
        ], $query->fetchAll());
    }

    public function create(
        int $customerId,
        ?string $deliveryAddress,
        ?int $deliverySlotId = null,
        ?int $addressId = null
    ): array {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) { $this->pdo->beginTransaction(); }

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
                    'SELECT id, is_active, capacity, starts_at > UTC_TIMESTAMP() AS is_future
                     FROM delivery_slots
                     WHERE id = :id
                     FOR UPDATE'
                );
                $slotQuery->execute(['id' => $deliverySlotId]);
                $slot = $slotQuery->fetch();

                if ($slot === false || !(bool) $slot['is_active'] || !(bool) $slot['is_future']) {
                    throw new OrderException(
                        409,
                        'The delivery slot is unavailable.'
                    );
                }
                // The locked slot serializes bookings; a locking read sees the latest committed orders.
                $bookings = $this->pdo->prepare(
                    "SELECT id FROM orders WHERE delivery_slot_id = :slot AND status <> 'cancelled' FOR UPDATE"
                );
                $bookings->execute(['slot' => $deliverySlotId]);
                if (count($bookings->fetchAll()) >= (int) $slot['capacity']) {
                    throw new OrderException(409, 'The delivery slot is unavailable.');
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

            $savedOrder = $this->find($orderId);
            if ($ownsTransaction) { $this->pdo->commit(); }
            return $savedOrder;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                if ($ownsTransaction) { $this->pdo->rollBack(); }
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

        return $this->formatOrder($order);
    }

    private function formatOrder(array $order): array
    {
        $itemsQuery = $this->pdo->prepare(
            'SELECT product_id, product_name, unit, quantity, unit_price
             FROM order_items
             WHERE order_id = :order
             ORDER BY id ASC'
        );
        $itemsQuery->execute(['order' => $order['id']]);

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

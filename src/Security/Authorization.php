<?php
declare(strict_types=1);

namespace Foodwell\Security;

use Foodwell\Http\Response;
use Foodwell\Http\ResourceInput;
use PDO;
use Throwable;

/** Authentication and authorization around a complete database transaction. */
final class Authorization
{
    public function __construct(private PDO $db) {}

    public function protect(callable $handler, string $policy): callable
    {
        if (!in_array($policy, ['staff', 'owner', 'customer_owner', 'customer', 'order', 'order_history', 'checkout',
            'review_author', 'review_author_or_admin'], true)) {
            throw new \InvalidArgumentException('Unknown authorization policy.');
        }
        return function (array $params) use ($handler, $policy): void {
            header('Cache-Control: no-store');
            $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
            if (!is_string($header) || !preg_match('/\ABearer ([0-9a-f]{64})\z/i', $header, $match)) {
                $this->unauthorized(); return;
            }
            $this->db->beginTransaction();
            try {
                // Lock the actor before the session, matching account deactivation.
                $lookup = $this->db->prepare('SELECT customer_id FROM auth_sessions WHERE token_hash = ?');
                $lookup->execute([hash('sha256', $match[1])]);
                $id = $lookup->fetchColumn();
                if ($id === false) { $this->db->rollBack(); $this->unauthorized(); return; }
                $actorQuery = $this->db->prepare('SELECT id, role, is_active FROM customers WHERE id = ? FOR UPDATE');
                $actorQuery->execute([$id]); $actor = $actorQuery->fetch();
                $sessionQuery = $this->db->prepare('SELECT id FROM auth_sessions WHERE token_hash = ? AND customer_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
                $sessionQuery->execute([hash('sha256', $match[1]), $id]);
                if (!$actor || !(bool)$actor['is_active'] || $sessionQuery->fetchColumn() === false) {
                    $this->db->rollBack(); $this->unauthorized(); return;
                }
                $ownerId = $params['id'] ?? null;
                if ($policy === 'order' || $policy === 'order_history') {
                    $order = $this->db->prepare('SELECT customer_id FROM orders WHERE id = ?');
                    $order->execute([$ownerId]);
                    $ownerId = $order->fetchColumn();
                    if ($ownerId === false) { $this->db->rollBack(); Response::error(404, 'Order not found.'); return; }
                } elseif ($policy === 'review_author' || $policy === 'review_author_or_admin') {
                    $review = $this->db->prepare('SELECT customer_id FROM product_reviews WHERE id = ? FOR UPDATE');
                    $review->execute([ResourceInput::id($params['reviewId'])]);
                    $ownerId = $review->fetchColumn();
                    if ($ownerId === false) {
                        $this->db->rollBack();
                        Response::error(404, 'Review not found.');
                        return;
                    }
                } elseif ($policy === 'checkout') {
                    $body = json_decode((string)file_get_contents('php://input'), true);
                    $ownerId = is_array($body) ? ($body['customerId'] ?? null) : null;
                    if (!is_int($ownerId) || $ownerId < 1) { $this->db->rollBack(); Response::error(422, 'Valid customerId required.'); return; }
                }
                $isOwner = (string) $actor['id'] === (string) $ownerId;
                $isCustomer = $actor['role'] === 'customer';
                $isAdmin = $actor['role'] === 'admin';
                $allowed = match ($policy) {
                    'customer' => $isCustomer,
                    'customer_owner', 'review_author' => $isCustomer && $isOwner,
                    'review_author_or_admin' => $isAdmin || ($isCustomer && $isOwner),
                    'staff' => in_array($actor['role'], ['staff', 'admin'], true),
                    'order_history' => $isOwner || $isAdmin || $actor['role'] === 'staff',
                    default => $isAdmin || $isOwner,
                };
                if (!$allowed) { $this->db->rollBack(); Response::error(403, 'Forbidden.'); return; }
                $params['actorId'] = (int) $actor['id'];
                ob_start();
                try {
                    $handler($params);
                    if (http_response_code() >= 400) { $this->db->rollBack(); }
                    else { $this->db->commit(); }
                    $output = ob_get_clean(); echo $output;
                } catch (Throwable $e) {
                    ob_end_clean(); throw $e;
                }
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) { $this->db->rollBack(); }
                throw $e;
            }
        };
    }

    private function unauthorized(): void
    {
        Response::error(401, 'Invalid or expired session.', ['WWW-Authenticate' => 'Bearer']);
    }
}

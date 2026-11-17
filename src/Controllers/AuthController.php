<?php
declare(strict_types=1);

namespace Foodwell\Controllers;

use Foodwell\Env;
use Foodwell\Http\Response;
use PDO;
use Throwable;

/** Opaque sessions. Route-level authorization is implemented separately in #46. */
final class AuthController
{
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    public function __construct(private PDO $db) {}

    public function login(array $params = []): void
    {
        header('Cache-Control: no-store');
        $input = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($input) || array_diff(array_keys($input), ['email', 'password']) || !isset($input['email'], $input['password']) || !is_string($input['email']) || !is_string($input['password']) || strlen($input['email']) > 254 || strlen($input['password']) > 255 || $input['password'] === '' || !filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL)) {
            Response::error(400, 'Invalid input.');
            return;
        }
        $secret = Env::get('AUTH_LIMITER_SECRET', '');
        if (strlen($secret) < 32) {
            Response::error(503, 'Authentication is unavailable.');
            return;
        }
        $email = strtolower(trim($input['email']));
        $accountKey = hash_hmac('sha256', 'account:' . $email, $secret);
        $sourceKey = hash_hmac('sha256', 'source:' . ($_SERVER['REMOTE_ADDR'] ?? ''), $secret);
        // Both rows are locked in the same order, so simultaneous attempts share a budget.
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare('INSERT IGNORE INTO auth_login_limits (limit_key, attempts, window_start) VALUES (?, 0, UTC_TIMESTAMP())');
            $keys = [$accountKey, $sourceKey]; sort($keys, SORT_STRING);
            foreach ($keys as $key) { $insert->execute([$key]); }
            $read = $this->db->prepare('SELECT attempts, window_start, TIMESTAMPDIFF(SECOND, window_start, UTC_TIMESTAMP()) AS age FROM auth_login_limits WHERE limit_key = ? FOR UPDATE');
            $rows = [];
            foreach ($keys as $key) {
                $read->execute([$key]); $row = $read->fetch();
                if ((int)$row['age'] >= 900 || (int)$row['age'] < 0) {
                    $reset = $this->db->prepare('UPDATE auth_login_limits SET attempts = 0, window_start = UTC_TIMESTAMP() WHERE limit_key = ?');
                    $reset->execute([$key]); $row['attempts'] = 0; $row['age'] = 0;
                }
                $rows[$key] = $row;
            }
            if ((int)$rows[$accountKey]['attempts'] >= 5 || (int)$rows[$sourceKey]['attempts'] >= 30) {
                $wait = max((int)$rows[$accountKey]['attempts'] >= 5 ? 900 - (int)$rows[$accountKey]['age'] : 0, (int)$rows[$sourceKey]['attempts'] >= 30 ? 900 - (int)$rows[$sourceKey]['age'] : 0, 1);
                $this->db->commit();
                $this->event('login_throttled');
                Response::error(429, 'Too many login attempts.', ['Retry-After' => (string)$wait]);
                return;
            }
            $query = $this->db->prepare('SELECT id, first_name, last_name, email, address, phone, role, is_active, created_at, updated_at, password_hash FROM customers WHERE email = ? LIMIT 1 FOR UPDATE');
            $query->execute([$email]); $customer = $query->fetch();
            $valid = password_verify($input['password'], $customer['password_hash'] ?? self::DUMMY_HASH);
            $inc = $this->db->prepare('UPDATE auth_login_limits SET attempts = attempts + 1 WHERE limit_key = ?');
            $inc->execute([$sourceKey]);
            if (!$customer || !$valid || !(int)$customer['is_active']) {
                $inc->execute([$accountKey]); $this->db->commit();
                $this->event('login_failure');
                Response::error(401, 'Invalid email or password.', ['WWW-Authenticate' => 'Bearer']);
                return;
            }
            $reset = $this->db->prepare('UPDATE auth_login_limits SET attempts = 0, window_start = UTC_TIMESTAMP() WHERE limit_key = ?');
            $reset->execute([$accountKey]);
            $token = bin2hex(random_bytes(32));
            $create = $this->db->prepare('INSERT INTO auth_sessions (customer_id, token_hash, created_at, expires_at) VALUES (?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 HOUR))');
            $create->execute([$customer['id'], hash('sha256', $token)]);
            $expiry = $this->db->query('SELECT expires_at FROM auth_sessions WHERE id = ' . (int)$this->db->lastInsertId())->fetchColumn();
            $this->db->commit();
            $this->event('login_success', (int)$customer['id']);
            Response::json(201, ['accessToken' => $token, 'tokenType' => 'Bearer', 'expiresAt' => str_replace(' ', 'T', $expiry) . 'Z', 'customer' => $this->customer($customer)]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function current(array $params = []): void
    {
        header('Cache-Control: no-store');
        $session = $this->session();
        if ($session === null) { return; }
        Response::json(200, ['customer' => $this->customer($session), 'expiresAt' => str_replace(' ', 'T', $session['expires_at']) . 'Z']);
    }

    public function logout(array $params = []): void
    {
        header('Cache-Control: no-store');
        $session = $this->session();
        if ($session === null) { return; }
        $stmt = $this->db->prepare('UPDATE auth_sessions SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([$session['session_id']]);
        if ($stmt->rowCount() !== 1) { $this->unauthorized(); return; }
        $this->event('logout', (int)$session['id']);
        http_response_code(204);
    }

    private function session(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || !preg_match('/\ABearer ([0-9a-f]{64})\z/i', $header, $match)) { $this->unauthorized(); return null; }
        $stmt = $this->db->prepare('SELECT s.id AS session_id, s.expires_at, c.id, c.first_name, c.last_name, c.email, c.address, c.phone, c.role, c.is_active, c.created_at, c.updated_at FROM auth_sessions s JOIN customers c ON c.id = s.customer_id WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > UTC_TIMESTAMP() AND c.is_active = 1');
        $stmt->execute([hash('sha256', $match[1])]);
        $row = $stmt->fetch();
        if (!$row) { $this->unauthorized(); return null; }
        return $row;
    }

    private function unauthorized(): void { Response::error(401, 'Invalid or expired session.', ['WWW-Authenticate' => 'Bearer']); }
    private function customer(array $row): array
    {
        return ['id' => (int)$row['id'], 'firstName' => $row['first_name'], 'lastName' => $row['last_name'], 'email' => $row['email'], 'address' => $row['address'], 'phone' => $row['phone'], 'role' => $row['role'], 'isActive' => (bool)$row['is_active'], 'createdAt' => str_replace(' ', 'T', $row['created_at']) . 'Z', 'updatedAt' => str_replace(' ', 'T', $row['updated_at']) . 'Z'];
    }
    private function event(string $name, ?int $actor = null): void
    {
        error_log((string)json_encode(['event' => $name, 'requestId' => $_SERVER['FOODWELL_REQUEST_ID'] ?? null, 'actorId' => $actor, 'time' => gmdate('c')]));
    }
}

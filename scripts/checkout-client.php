<?php
declare(strict_types=1);

// Explicit local demonstration: this consumer creates a real order in the configured test database.
require dirname(__DIR__) . '/vendor/autoload.php';
Foodwell\Env::load(dirname(__DIR__) . '/.env');
if ($argc !== 4 || !ctype_digit($argv[1]) || !ctype_digit($argv[2]) || trim($argv[3]) === '') {
    fwrite(STDERR, "Usage: php scripts/checkout-client.php CUSTOMER_ID PRODUCT_ID 'SYNTHETIC DELIVERY ADDRESS'\n"); exit(1);
}
$base = rtrim(Foodwell\Env::get('DELIVERY_API_BASE_URL', 'http://localhost:8080/api/v1'), '/');
$url = parse_url($base);
if ($url === false || !in_array($url['scheme'] ?? '', ['http','https'], true) || !in_array($url['host'] ?? '', ['localhost','127.0.0.1','::1'], true) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
    fwrite(STDERR, "Use a loopback DELIVERY_API_BASE_URL without credentials, query or fragment.\n"); exit(1);
}
function callApi(string $method, string $path, ?array $body = null): array {
    global $base;
    $ch = curl_init($base . $path);
    $options = [CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>2, CURLOPT_TIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json']];
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    curl_setopt_array($ch, $options); $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException('Request failed: ' . $error);
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    echo "$method $path -> HTTP $status\n";
    if ($status >= 400) throw new RuntimeException($data['error']['message'] ?? 'Request rejected.');
    return $data;
}
try {
    $customer=(int)$argv[1]; $product=(int)$argv[2];
    $catalog=callApi('GET', '/products?limit=5');
    echo 'Products on first page: ' . count($catalog['data']) . "\n";
    $cart=callApi('POST', "/customers/$customer/cart/items", ['productId'=>$product,'quantity'=>1]);
    echo 'Cart subtotal: ' . $cart['subtotal'] . "\n";
    $order=callApi('POST', '/orders', ['customerId'=>$customer,'deliveryAddress'=>trim($argv[3])]);
    echo 'Created order #' . $order['id'] . "\n";
    $saved=callApi('GET', '/orders/' . $order['id']);
    echo 'Saved order total: ' . $saved['total'] . '; lines: ' . count($saved['items']) . "\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }

<?php
declare(strict_types=1);

// A PHP consumer of Foodwell's composite API, not a direct provider request.
require dirname(__DIR__) . '/vendor/autoload.php';
Foodwell\Env::load(dirname(__DIR__) . '/.env');
$code = $argv[1] ?? '';
if ($code === '') { fwrite(STDERR, "Usage: php scripts/delivery-client.php \"H3N 1X1\"\n"); exit(1); }
if (!extension_loaded('curl')) { fwrite(STDERR, "Enable PHP cURL to use this consumer.\n"); exit(1); }
$base = rtrim(Foodwell\Env::get('DELIVERY_API_BASE_URL', 'http://localhost/foodwell-api/api/v1'), '/');
$parts = parse_url($base);
if ($parts === false || !in_array($parts['scheme'] ?? '', ['http','https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
    fwrite(STDERR, "Configure a valid DELIVERY_API_BASE_URL without credentials or query.\n"); exit(1);
}
$ch = curl_init($base . '/delivery-options?postalCode=' . rawurlencode($code));
$body = ''; $options = [CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => 8000, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
        if (strlen($body) + strlen($chunk) > 131072) { return 0; } $body .= $chunk; return strlen($chunk);
    }];
$ca = Foodwell\Env::get('POSTAL_CA_BUNDLE', ''); if ($ca !== '') { $options[CURLOPT_CAINFO] = $ca; }
curl_setopt_array($ch, $options); $ok = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
if ($ok === false) { fwrite(STDERR, "Foodwell API could not be reached within the configured limits.\n"); exit(1); }
$data = json_decode($body, true);
if (!is_array($data)) { fwrite(STDERR, "Foodwell API did not return JSON.\n"); exit(1); }
echo "Foodwell composite API - HTTP $status\n";
echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($status === 200 ? 0 : 1);

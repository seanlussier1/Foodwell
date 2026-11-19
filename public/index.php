<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$requestId = bin2hex(random_bytes(16));
$_SERVER['FOODWELL_REQUEST_ID'] = $requestId;
header('X-Request-ID: ' . $requestId);

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    error_log(json_encode([
        'level' => 'warning',
        'message' => 'PHP warning or notice',
        'severity' => $severity,
        'file' => basename($file),
        'line' => $line,
        'requestId' => $_SERVER['FOODWELL_REQUEST_ID'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return true;
});

register_shutdown_function(static function () use ($requestId): void {
    $error = error_get_last();

    if ($error === null) {
        return;
    }

    $fatalErrors = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR,
        E_RECOVERABLE_ERROR,
    ];

    if (!in_array($error['type'], $fatalErrors, true)) {
        return;
    }

    error_log(json_encode([
        'level' => 'critical',
        'message' => 'Fatal PHP error',
        'requestId' => $requestId,
        'severity' => $error['type'],
        'file' => basename($error['file']),
        'line' => $error['line'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $path = (string) parse_url(
        $_SERVER['REQUEST_URI'] ?? '/',
        PHP_URL_PATH
    );

    $isApiRequest = $path === '/api/v1'
        || str_starts_with($path, '/api/v1/');

    if (
        $isApiRequest
        && !headers_sent()
        && http_response_code() < 400
    ) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        echo json_encode([
            'error' => [
                'status' => 500,
                'message' => 'Internal server error.',
                'requestId' => $requestId,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

// PHP built-in server (`composer start`): serve static files and the home page directly.
if (PHP_SAPI === 'cli-server') {
    if ($path === '/') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        return true;
    }
    if (!str_contains($path, '..') && is_file(__DIR__ . $path)) {
        return false;
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => [
            'status' => 500,
            'message' => 'Dependencies missing. Run: composer install',
            'requestId' => $requestId,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}
require $autoload;

use Foodwell\Controllers\AuthController;
use Foodwell\Controllers\FavoriteController;
use Foodwell\Controllers\InventoryAdjustmentController;
use Foodwell\Security\Authorization;
use Foodwell\Controllers\ProductController;
use Foodwell\Controllers\CustomerController;
use Foodwell\Controllers\CartController;
use Foodwell\Controllers\DeliveryOptionController;
use Foodwell\Controllers\DeliverySlotController;
use Foodwell\Controllers\CategoryController;
use Foodwell\Controllers\OrderController;
use Foodwell\Database;
use Foodwell\Env;
use Foodwell\Http\Response;
use Foodwell\Http\Router;
use Foodwell\Http\Cors;
use Foodwell\Http\Negotiation;
use Foodwell\Repositories\ProductRepository;
use Foodwell\Repositories\CustomerRepository;
use Foodwell\Repositories\InventoryAdjustmentRepository;
use Foodwell\Repositories\CartRepository;
use Foodwell\Repositories\DeliveryOptionRepository;
use Foodwell\Repositories\DeliverySlotRepository;
use Foodwell\Services\PostalAreaClient;
use Foodwell\Repositories\CategoryRepository;
use Foodwell\Repositories\OrderRepository;

Env::load(dirname(__DIR__) . '/.env');

// When installed in a subfolder (e.g. Wamp's htdocs/foodwell-api), set APP_BASE_PATH=/foodwell-api
$base = rtrim((string) Env::get('APP_BASE_PATH', ''), '/');
if ($base !== '' && str_starts_with($path, $base . '/')) {
    $path = substr($path, strlen($base));
}

const API_PREFIX = '/api/v1';

// The Wamp root rewrite intentionally hides repository folders. Expose only
// these three client assets, not the /client directory or arbitrary files.
if ($path === '/client' && !str_ends_with((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/')) {
    header('Location: ' . $base . '/client/', true, 302);
    return;
}
$clientAssets = [
    '/client' => ['index.html', 'text/html; charset=utf-8'],
    '/client/index.html' => ['index.html', 'text/html; charset=utf-8'],
    '/client/customers.html' => ['customers.html', 'text/html; charset=utf-8'],
    '/client/signin.html' => ['signin.html', 'text/html; charset=utf-8'],
    '/client/signin.js' => ['signin.js', 'text/javascript; charset=utf-8'],
    '/client/register.html' => ['register.html', 'text/html; charset=utf-8'],
    '/client/register.js' => ['register.js', 'text/javascript; charset=utf-8'],
    '/client/shell.js' => ['shell.js', 'text/javascript; charset=utf-8'],
    '/client/styles.css' => ['styles.css', 'text/css; charset=utf-8'],
    '/client/auth.js' => ['auth.js', 'text/javascript; charset=utf-8'],
    '/client/app.js' => ['app.js', 'text/javascript; charset=utf-8'],
    '/client/delivery.html' => ['delivery.html', 'text/html; charset=utf-8'],
    '/client/delivery.js' => ['delivery.js', 'text/javascript; charset=utf-8'],
    '/client/products.html' => ['products.html', 'text/html; charset=utf-8'],
    '/client/products.js' => ['products.js', 'text/javascript; charset=utf-8'],
    '/client/shop.html' => ['shop.html', 'text/html; charset=utf-8'],
    '/client/shop.js' => ['shop.js', 'text/javascript; charset=utf-8'],
];
if (isset($clientAssets[$path])) {
    [$file, $contentType] = $clientAssets[$path];
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    readfile(dirname(__DIR__) . '/client/' . $file);
    return;
}

if (str_starts_with($path, API_PREFIX . '/') || $path === API_PREFIX) {
    if (!Cors::handle()) {
        return;
    }
}

try {
    $products = new ProductController(new ProductRepository(Database::connection()));
    $customers = new CustomerController(new CustomerRepository(Database::connection()));
    $categories = new CategoryController(new CategoryRepository(Database::connection()));
    $carts = new CartController(new CartRepository(Database::connection()));
    $orders = new OrderController(new OrderRepository(Database::connection()));
    $deliverySlots = new DeliverySlotController(new DeliverySlotRepository(Database::connection()));
    $deliveryOptions = new DeliveryOptionController(
        new PostalAreaClient(dirname(__DIR__) . '/var/postal-cache', 86400, Env::get('POSTAL_CA_BUNDLE', '')),
        new DeliveryOptionRepository(Database::connection())
    );

    $auth = new AuthController(Database::connection());
    $favorites = new FavoriteController(Database::connection());
    $inventory = new InventoryAdjustmentController(new InventoryAdjustmentRepository(Database::connection()));
    $authorization = new Authorization(Database::connection());
    $router = new Router();
    $router->post(API_PREFIX . '/auth/sessions', [$auth, 'login']);
    $router->get(API_PREFIX . '/auth/sessions/current', [$auth, 'current']);
    $router->delete(API_PREFIX . '/auth/sessions/current', [$auth, 'logout']);

    $router->post(API_PREFIX . '/customers', [$customers, 'register']);
    $router->get(API_PREFIX . '/customers', $authorization->protect([$customers, 'index'], 'staff'));
    $router->get(API_PREFIX . '/customers/{id}', $authorization->protect([$customers, 'show'], 'owner'));
    $router->put(API_PREFIX . '/customers/{id}', $authorization->protect([$customers, 'update'], 'owner'));
    $router->patch(API_PREFIX . '/customers/{id}', $authorization->protect([$customers, 'deactivate'], 'staff'));
    $router->get(API_PREFIX . '/customers/{id}/cart', $authorization->protect([$carts, 'show'], 'owner'));
    $router->post(API_PREFIX . '/customers/{id}/cart/items', $authorization->protect([$carts, 'add'], 'owner'));
    $router->put(API_PREFIX . '/customers/{id}/cart/items/{itemId}', $authorization->protect([$carts, 'update'], 'owner'));
    $router->delete(API_PREFIX . '/customers/{id}/cart/items/{itemId}', $authorization->protect([$carts, 'remove'], 'owner'));
    $router->get(API_PREFIX . '/customers/{id}/favorites', $authorization->protect([$favorites, 'index'], 'customer_owner'));
    $router->put(API_PREFIX . '/customers/{id}/favorites/{productId}', $authorization->protect([$favorites, 'save'], 'customer_owner'));
    $router->delete(API_PREFIX . '/customers/{id}/favorites/{productId}', $authorization->protect([$favorites, 'remove'], 'customer_owner'));

    $router->get(API_PREFIX . '/delivery-options', [$deliveryOptions, 'index']);

    // Delivery slots Anyone can read them. Creating, changing and
    // deleting go through $authorization, which checks
    // the login and role before the controller runs: no login = 401,
    // customer = 403. Customers attach a slot to their order at
    // checkout by sending "deliverySlotId" to POST /orders.
    // Representation matrix (#65): every catalog route (products, categories,
    // delivery slots) ANSWERS in JSON, XML or YAML, chosen by the Accept header
    // (anything else is 406). Request BODIES stay JSON-only on every route: a
    // POST/PUT with another Content-Type gets 415. See docs/REPRESENTATION_MATRIX.md.
    $catalogFormats = [Negotiation::JSON, Negotiation::XML, Negotiation::YAML];

    $router->get(API_PREFIX . '/delivery-slots', [$deliverySlots, 'index'], $catalogFormats);
    $router->get(API_PREFIX . '/delivery-slots/{id}', [$deliverySlots, 'show'], $catalogFormats);
    $router->post(API_PREFIX . '/delivery-slots', $authorization->protect([$deliverySlots, 'store'], 'staff'), $catalogFormats);
    $router->put(API_PREFIX . '/delivery-slots/{id}', $authorization->protect([$deliverySlots, 'update'], 'staff'), $catalogFormats);
    $router->delete(API_PREFIX . '/delivery-slots/{id}', $authorization->protect([$deliverySlots, 'destroy'], 'staff'), $catalogFormats);

    $router->get(API_PREFIX . '/categories', [$categories, 'index'], $catalogFormats);
    $router->get(API_PREFIX . '/categories/{id}', [$categories, 'show'], $catalogFormats);
    $router->post(API_PREFIX . '/categories', $authorization->protect([$categories, 'store'], 'staff'), $catalogFormats);
    $router->delete(API_PREFIX . '/categories/{id}', $authorization->protect([$categories, 'destroy'], 'staff'), $catalogFormats);
    $router->post(API_PREFIX . '/orders', $authorization->protect([$orders, 'create'], 'checkout'));
    $router->get(API_PREFIX . '/orders/{id}', $authorization->protect([$orders, 'show'], 'order'));
    $router->patch(API_PREFIX . '/orders/{id}', $authorization->protect([$orders, 'updateStatus'], 'staff'));
    $router->get(API_PREFIX . '/customers/{id}/orders', $authorization->protect([$orders, 'customerHistory'], 'owner'));
    $router->get(API_PREFIX . '/orders/{id}/status-events', $authorization->protect([$orders, 'statusHistory'], 'order_history'));

    $router->get(API_PREFIX . '/products', [$products, 'index'], $catalogFormats);
    $router->get(API_PREFIX . '/products/{id}', [$products, 'show'], $catalogFormats);
    $router->post(API_PREFIX . '/products', $authorization->protect([$products, 'store'], 'staff'), $catalogFormats);
    $router->put(API_PREFIX . '/products/{id}', $authorization->protect([$products, 'update'], 'staff'), $catalogFormats);
    $router->delete(API_PREFIX . '/products/{id}', $authorization->protect([$products, 'destroy'], 'staff'), $catalogFormats);

    // Inventory adjustments (Will): staff or admin only, JSON only. The stock
    // change and its audit row are saved in the transaction Authorization opens.
    // No PUT/PATCH/DELETE on purpose: the audit log can only grow.
    $router->get(API_PREFIX . '/products/{id}/inventory-adjustments', $authorization->protect([$inventory, 'index'], 'staff'));
    $router->post(API_PREFIX . '/products/{id}/inventory-adjustments', $authorization->protect([$inventory, 'store'], 'staff'));
    $router->get(API_PREFIX . '/inventory-adjustments/{adjustmentId}', $authorization->protect([$inventory, 'show'], 'staff'));

    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
} catch (Throwable $e) {
    error_log(json_encode([
        'level' => 'error',
        'message' => 'Unhandled exception',
        'requestId' => $requestId,
        'exception' => get_class($e),
        'errorMessage' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    Response::error(500, 'Internal server error.');
}

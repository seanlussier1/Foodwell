<?php
declare(strict_types=1);

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
    echo '{"error":{"status":500,"message":"Dependencies missing. Run: composer install"}}';
    return;
}
require $autoload;

use Foodwell\Controllers\ProductController;
use Foodwell\Controllers\CustomerController;
use Foodwell\Controllers\CategoryController;
use Foodwell\Database;
use Foodwell\Env;
use Foodwell\Http\Response;
use Foodwell\Http\Router;
use Foodwell\Http\Cors;
use Foodwell\Repositories\ProductRepository;
use Foodwell\Repositories\CustomerRepository;
use Foodwell\Repositories\CategoryRepository;

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
    '/client/styles.css' => ['styles.css', 'text/css; charset=utf-8'],
    '/client/app.js' => ['app.js', 'text/javascript; charset=utf-8'],
];
if (isset($clientAssets[$path])) {
    [$file, $contentType] = $clientAssets[$path];
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    readfile(dirname(__DIR__) . '/client/' . $file);
    return;
}

if (str_starts_with($path, API_PREFIX . '/') || $path === API_PREFIX) {
    if (!Cors::handle()) { return; }
}

try {
    $products = new ProductController(new ProductRepository(Database::connection()));
    $customers = new CustomerController(new CustomerRepository(Database::connection()));
    $categories = new CategoryController(new CategoryRepository(Database::connection()));

    $router = new Router();
    $router->get(API_PREFIX . '/products',       [$products, 'index']);
    $router->get(API_PREFIX . '/products/{id}',  [$products, 'show']);
    $router->post(API_PREFIX . '/customers', [$customers, 'register']);
    $router->get(API_PREFIX . '/customers', [$customers, 'index']);
    $router->get(API_PREFIX . '/customers/{id}', [$customers, 'show']);
    $router->put(API_PREFIX . '/customers/{id}', [$customers, 'update']);
    $router->patch(API_PREFIX . '/customers/{id}', [$customers, 'deactivate']);
    $router->get(API_PREFIX . '/categories', [$categories, 'index']);
    $router->get(API_PREFIX . '/categories/{id}', [$categories, 'show']);
    $router->post(API_PREFIX . '/categories', [$categories, 'store']);
    $router->delete(API_PREFIX . '/categories/{id}', [$categories, 'destroy']);

    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
} catch (Throwable $e) {
    error_log($e->getMessage());
    Response::error(500, 'Internal server error.');
}
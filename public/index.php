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
use Foodwell\Database;
use Foodwell\Env;
use Foodwell\Http\Response;
use Foodwell\Http\Router;
use Foodwell\Repositories\ProductRepository;

Env::load(dirname(__DIR__) . '/.env');

// When installed in a subfolder (e.g. Wamp's htdocs/foodwell-api), set APP_BASE_PATH=/foodwell-api
$base = rtrim((string) Env::get('APP_BASE_PATH', ''), '/');
if ($base !== '' && str_starts_with($path, $base . '/')) {
    $path = substr($path, strlen($base));
}

const API_PREFIX = '/api/v1';

try {
    $products = new ProductController(new ProductRepository(Database::connection()));

    $router = new Router();
    $router->get(API_PREFIX . '/products',       [$products, 'index']);
    $router->get(API_PREFIX . '/products/{id}',  [$products, 'show']);

    $router->dispatch($_SERVER['REQUEST_METHOD'], $path);
} catch (Throwable $e) {
    error_log($e->getMessage());
    Response::error(500, 'Internal server error.');
}

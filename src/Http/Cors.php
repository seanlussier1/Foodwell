<?php
declare(strict_types=1);

namespace Foodwell\Http;

use Foodwell\Env;

final class Cors
{
    /** Return false when this middleware has completed/rejected the request. */
    public static function handle(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS', 'http://localhost:8081,http://127.0.0.1:8081'))));
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $sameOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        header('Vary: Origin');
        if ($origin !== '' && $origin !== $sameOrigin && !in_array($origin, $allowed, true)) {
            Response::error(403, 'This browser origin is not allowed.');
            return false;
        }
        if ($origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') { return true; }
        $methods = ['GET', 'POST', 'PUT', 'PATCH', 'OPTIONS'];
        $requestedMethod = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '';
        if ($requestedMethod !== '' && !in_array($requestedMethod, $methods, true)) {
            Response::error(405, 'Requested cross-origin method is not supported.', ['Allow' => implode(', ', $methods)]);
            return false;
        }
        $requestedHeaders = strtolower($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '');
        foreach (array_filter(array_map('trim', explode(',', $requestedHeaders))) as $name) {
            if (!in_array($name, ['accept', 'content-type', 'authorization'], true)) {
                Response::error(400, 'Requested cross-origin header is not supported.');
                return false;
            }
        }
        header('Access-Control-Allow-Methods: ' . implode(', ', $methods));
        header('Access-Control-Allow-Headers: Accept, Content-Type, Authorization');
        header('Access-Control-Max-Age: 600');
        header('Vary: Access-Control-Request-Method', false);
        header('Vary: Access-Control-Request-Headers', false);
        http_response_code(204);
        return false;
    }
}

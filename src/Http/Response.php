<?php
declare(strict_types=1);

namespace Foodwell\Http;

final class Response
{
    public static function json(int $status, mixed $payload, array $headers = []): void
    {
        http_response_code($status); // Set the HTTP status code ex: 200, 404, 500
        header('Content-Type: application/json; charset=utf-8'); // Set the Content-Type header to indicate JSON response
        foreach ($headers as $name => $value) {
            header("$name: $value"); // Set any additional headers provided in the $headers array
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);  // Output the JSON-encoded payload
    }

    /** Standard error body: {"error": {"status": 404, "message": "..."}} */
    public static function error(int $status, string $message, array $headers = [], array $details = []): void
    {
        $error = ['status' => $status, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        self::json($status, ['error' => $error], $headers);
        // Call the json method to send a standardized error response
    }
}

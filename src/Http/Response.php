<?php
declare(strict_types=1);

namespace Foodwell\Http;

final class Response
{
    private static string $format = Negotiation::JSON;
    public static function json(int $status, mixed $payload, array $headers = []): void
    {
        http_response_code($status); // Set the HTTP status code ex: 200, 404, 500
        header('Content-Type: application/json; charset=utf-8'); // Set the Content-Type header to indicate JSON response
        header('X-Content-Type-Options: nosniff');

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

        $requestId = $_SERVER['FOODWELL_REQUEST_ID'] ?? null;

        if (is_string($requestId) && $requestId !== '') {
            $error['requestId'] = $requestId;
        }

        if (self::$format === Negotiation::XML) {
            self::send($status, $error, $headers, 'error', 'item');
            return;
        }
        self::send($status, ['error' => $error], $headers);
    }

    public static function useFormat(string $mediaType): void
    {
        self::$format = $mediaType;
    }

    public static function send(int $status, array $payload, array $headers = [], string $xmlRoot = 'response', string $xmlItem = 'item'): void
    {
        if (self::$format === Negotiation::JSON) {
            self::json($status, $payload, $headers);
            return;
        }

        // Build the body first: if anything failed, no headers would be sent yet.
        $body = self::$format === Negotiation::XML
            ? Representation::xml($payload, $xmlRoot, $xmlItem)
            : Representation::yaml($payload);

        http_response_code($status);
        header('Content-Type: ' . self::$format . '; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        echo $body;
    }
    
}

<?php
declare(strict_types=1);

namespace Foodwell\Http;

use JsonException;
use stdClass;

final class ResourceInput
{
    public static function id(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4294967295],
        ]);
        return $id === false ? null : $id;
    }

    public static function paging(array $query): ?array
    {
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 2147483647],
        ]);
        $limit = filter_var($query['limit'] ?? '20', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        if (array_diff_key($query, ['page' => true, 'limit' => true]) || $page === false || $limit === false) {
            Response::error(400, 'Invalid pagination parameters.');
            return null;
        }
        return [$limit, ($page - 1) * $limit];
    }

    public static function jsonObject(): ?array
    {
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($contentType !== 'application/json') {
            Response::error(415, 'Content-Type must be application/json.');
            return null;
        }
        try {
            $value = json_decode((string) file_get_contents('php://input'), false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            Response::error(400, 'Malformed JSON body.');
            return null;
        }
        if (!$value instanceof stdClass) {
            Response::error(400, 'The request body must be a JSON object.');
            return null;
        }
        return (array) $value;
    }
}

<?php
declare(strict_types=1);

namespace Foodwell\Validation;

final class ProductInput
{
    private const MAX_NAME = 150;// Maximum length for product name
    private const MAX_UNIT = 30;// Maximum length for unit
    private const MAX_PRICE = 999999.99;// Maximum length for price
    private const MAX_STOCK = 1000000;// Maximum length for stock quantity
    private const MAX_URL = 255;// Maximum length for image URL
    private const MAX_ID = 4294967295;// Maximum length for product ID

    public static function validate(array $input, bool $creating = true): array
    {
        $data = [];
        $errors = [];

        $allowed = $creating
            ? ['name', 'price', 'unit', 'stock', 'categoryId', 'imageUrl']
            : ['name', 'price', 'unit', 'categoryId', 'imageUrl', 'isActive'];
        foreach ($input as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                $errors[$field] = ($field === 'stock')
                    ? 'Stock cannot be changed here. Use an inventory adjustment.'
                    : 'This field is not allowed for this operation.';
            }
        }

        $name = $input['name'] ?? null;
        if (!is_string($name)) {
            $errors['name'] = 'This field is required and must be a string.';
        } else {
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
                $errors['name'] = 'Use between 1 and ' . self::MAX_NAME . ' characters.';
            }
            $data['name'] = $name;
        }

        $price = $input['price'] ?? null;
        if (!is_int($price) && !is_float($price)) {
            $errors['price'] = 'This field is required and must be a number.';
        } elseif ($price < 0) {
            $errors['price'] = 'Price cannot be negative.';
        } elseif ($price > self::MAX_PRICE) {
            $errors['price'] = 'Price must be at most ' . self::MAX_PRICE . '.';
        } elseif (round((float) $price, 2) != $price) {
            $errors['price'] = 'Use at most 2 decimals.';
        } else {
            $data['price'] = (float) $price;
        }

        $unit = $input['unit'] ?? 'each';
        if (!is_string($unit) || trim($unit) === '' || mb_strlen(trim($unit)) > self::MAX_UNIT) {
            $errors['unit'] = 'Use a string of 1 to ' . self::MAX_UNIT . ' characters.';
        } else {
            $data['unit'] = trim($unit);
        }

        if ($creating) {
            $stock = $input['stock'] ?? 0;
            if (!is_int($stock) || $stock < 0 || $stock > self::MAX_STOCK) {
                $errors['stock'] = 'Use a whole number from 0 to ' . self::MAX_STOCK . '.';
            } else {
                $data['stock'] = $stock;
            }
        }

        $categoryId = $input['categoryId'] ?? null;
        if (!is_int($categoryId) || $categoryId < 1 || $categoryId > self::MAX_ID) {
            $errors['categoryId'] = 'This field is required and must be a positive whole number.';
        } else {
            $data['categoryId'] = $categoryId;
        }

        $imageUrl = $input['imageUrl'] ?? null;
        if ($imageUrl !== null) {
            $scheme = is_string($imageUrl) ? strtolower((string) parse_url($imageUrl, PHP_URL_SCHEME)) : '';
            if (
                !is_string($imageUrl)
                || strlen($imageUrl) > self::MAX_URL
                || filter_var($imageUrl, FILTER_VALIDATE_URL) === false
                || !in_array($scheme, ['http', 'https'], true)
            ) {
                $errors['imageUrl'] = 'Use an http(s) URL of at most ' . self::MAX_URL . ' characters, or null.';
            }
        }
        $data['imageUrl'] = $imageUrl;

        if (!$creating && array_key_exists('isActive', $input)) {
            if (!is_bool($input['isActive'])) {
                $errors['isActive'] = 'Use true or false.';
            } else {
                $data['isActive'] = $input['isActive'];
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }
}
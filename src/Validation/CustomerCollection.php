<?php
declare(strict_types=1);

namespace Foodwell\Validation;

final class CustomerCollection
{
    public static function validate(array $query): array
    {
        $data = ['page' => 1, 'limit' => 10, 'search' => '', 'isActive' => null];
        $errors = [];
        foreach ($query as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $errors[$key] = 'Unknown query parameter.';
            } elseif (!is_string($value)) {
                $errors[$key] = 'Use one scalar query value.';
            }
        }
        foreach (['page' => 2147483647, 'limit' => 100] as $key => $max) {
            if (!array_key_exists($key, $query) || isset($errors[$key])) { continue; }
            $value = $query[$key];
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
            if (!preg_match('/^[1-9][0-9]*$/D', $value) || $integer === false) {
                $errors[$key] = "Use an integer from 1 to $max.";
            } else {
                $data[$key] = $integer;
            }
        }
        if (isset($query['search']) && !isset($errors['search'])) {
            $value = trim($query['search']);
            $length = preg_match_all('/./us', $value);
            if ($length === false || $length > 100) {
                $errors['search'] = 'Use valid UTF-8 text of at most 100 characters.';
            } else {
                $data['search'] = $value;
            }
        }
        if (isset($query['isActive']) && !isset($errors['isActive'])) {
            if (!in_array($query['isActive'], ['true', 'false'], true)) {
                $errors['isActive'] = 'Use true or false.';
            } else {
                $data['isActive'] = $query['isActive'] === 'true';
            }
        }
        return ['data' => $data, 'errors' => $errors];
    }
}

<?php
declare(strict_types=1);

namespace Foodwell\Validation;

final class CustomerRegistration
{
    /** @return array{data:array<string,mixed>,errors:array<string,string>} */
    public static function validate(array $input, bool $registration = true): array
    {
        $data = [];
        $errors = [];
        $allowed = ['firstName', 'lastName', 'email', 'address', 'phone'];
        if ($registration) { $allowed[] = 'password'; }
        foreach ($input as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                $errors[$field] = 'This field is not allowed for this operation.';
            }
        }
        foreach (['firstName' => 100, 'lastName' => 100, 'email' => 254] as $field => $max) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                $errors[$field] = 'This field is required and must be a string.';
                continue;
            }
            $value = trim($input[$field]);
            $length = preg_match_all('/./us', $value);
            if ($length === 0 || $length > $max) {
                $errors[$field] = "Use between 1 and $max characters.";
            }
            $data[$field] = $value;
        }
        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
            if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Enter a valid email address.';
            }
        }
        if ($registration) {
            $password = $input['password'] ?? null;
            if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
                $errors['password'] = 'Use a password of 8 to 72 bytes without null characters.';
            } else {
                // Do not trim passwords; reject lengths that bcrypt would truncate.
                $data['password'] = $password;
            }
        }
        foreach (['address' => 500, 'phone' => 30] as $field => $max) {
            $value = $input[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                $errors[$field] = 'Use a string or null.';
                continue;
            }
            $value = $value === null ? null : trim($value);
            if ($value !== null && preg_match_all('/./us', $value) > $max) {
                $errors[$field] = "Use at most $max characters.";
            }
            $data[$field] = $value === '' ? null : $value;
        }
        return ['data' => $data, 'errors' => $errors];
    }
}

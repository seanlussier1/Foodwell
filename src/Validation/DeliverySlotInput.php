<?php
declare(strict_types=1);

namespace Foodwell\Validation;

use DateTimeImmutable;
use DateTimeZone;

final class DeliverySlotInput
{
    public const MAX_CAPACITY = 1000;
    public const MAX_WINDOW_HOURS = 12;
    public const MAX_DAYS_AHEAD = 365;

    private const FIELDS = ['startsAt', 'endsAt', 'capacity', 'isActive'];

    // 2026-11-20T14:00Z, 2026-11-20T14:00:00Z, 2026-11-20T09:00:00-05:00
    private const DATE_TIME = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})\z/';

    public static function validate(array $input): array
    {
        $errors = [];
        $data = [];

        // Unknown fields are refused, not ignored.
        foreach (array_keys($input) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $errors[(string) $field] = 'This field is not allowed.';
            }
        }

        $starts = self::readTime($input, 'startsAt', $errors);
        $ends = self::readTime($input, 'endsAt', $errors);

        if ($starts !== null && $ends !== null) {
            $seconds = $ends->getTimestamp() - $starts->getTimestamp();
            if ($seconds <= 0) {
                $errors['endsAt'] = 'endsAt must be later than startsAt.';
            } elseif ($seconds > self::MAX_WINDOW_HOURS * 3600) {
                $errors['endsAt'] = 'A delivery window can be at most ' . self::MAX_WINDOW_HOURS . ' hours long.';
            }
        }
        if ($starts !== null) {
            $latest = new DateTimeImmutable('+' . self::MAX_DAYS_AHEAD . ' days', new DateTimeZone('UTC'));
            if ($starts > $latest) {
                $errors['startsAt'] = 'startsAt can be at most ' . self::MAX_DAYS_AHEAD . ' days ahead.';
            }
            $data['startsAt'] = $starts->format('Y-m-d H:i:s');
        }
        if ($ends !== null) {
            $data['endsAt'] = $ends->format('Y-m-d H:i:s');
        }

        if (!array_key_exists('capacity', $input)) {
            $errors['capacity'] = 'capacity is required.';
        } elseif (!is_int($input['capacity']) || $input['capacity'] < 1 || $input['capacity'] > self::MAX_CAPACITY) {
            // is_int: 2.0, "2" and true are rejected rather than silently converted.
            $errors['capacity'] = 'capacity must be a whole number from 1 to ' . self::MAX_CAPACITY . '.';
        } else {
            $data['capacity'] = $input['capacity'];
        }

        // Optional. The controller decides what "left out" means (true on create, keep on update).
        if (array_key_exists('isActive', $input)) {
            if (!is_bool($input['isActive'])) {
                $errors['isActive'] = 'isActive must be true or false.';
            } else {
                $data['isActive'] = $input['isActive'];
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /** True when a date-time is later than the current time. */
    public static function isFuture(string $utc): bool
    {
        $zone = new DateTimeZone('UTC');
        return new DateTimeImmutable($utc, $zone) > new DateTimeImmutable('now', $zone);
    }

    /** Parses one required time field; records an error and returns null when invalid. */
    private static function readTime(array $input, string $field, array &$errors): ?DateTimeImmutable
    {
        if (!array_key_exists($field, $input)) {
            $errors[$field] = "$field is required.";
            return null;
        }
        $value = $input[$field];
        $message = "$field must be an ISO 8601 date-time with a time zone, for example 2026-11-20T14:00:00Z.";

        if (!is_string($value) || preg_match(self::DATE_TIME, $value) !== 1) {
            $errors[$field] = $message;
            return null;
        }

        // Normalise so one format string handles both "Z" and missing seconds.
        $normalised = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        if (strlen($normalised) === 22) {               // no seconds: YYYY-MM-DDTHH:MM+hh:mm
            $normalised = substr($normalised, 0, 16) . ':00' . substr($normalised, 16);
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalised);
        
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:sP') !== $normalised) {
            $errors[$field] = $message;
            return null;
        }
        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }
}

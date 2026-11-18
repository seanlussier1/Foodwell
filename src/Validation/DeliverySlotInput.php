<?php
declare(strict_types=1);

namespace Foodwell\Validation;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Checks the JSON body sent to POST and PUT /delivery-slots before anything
 * touches the database.
 */
final class DeliverySlotInput
{
    // Limits
    public const MAX_CAPACITY = 1000;      // most orders one slot can hold
    public const MAX_WINDOW_HOURS = 12;    // longest allowed window (end - start)
    public const MAX_DAYS_AHEAD = 365;     // a slot can start at most a year from now (catches typos like 2062)

    // The only fields a client may send. Anything else (like "id" or a typo)
    // is an error instead of being silently ignored.
    private const FIELDS = ['startsAt', 'endsAt', 'capacity', 'isActive'];

    // What a valid time looks like. All of these pass:
    //   2026-11-20T14:00Z           (UTC, no seconds)
    //   2026-11-20T14:00:00Z        (UTC)
    //   2026-11-20T09:00:00-05:00   (Montreal time in winter)
    private const DATE_TIME = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})\z/';

    /**
     * Checks every field and collects ALL the problems at once so the client can fix everything in one try.
     *
     * @param array<string,mixed> $input the request body, already decoded from JSON
     * @return array{data: array<string,mixed>, errors: array<string,string>}
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $data = [];

        //Refuse any field we don't know.
        foreach (array_keys($input) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $errors[(string) $field] = 'This field is not allowed.';
            }
        }

        //Read both times. readTime() adds an error and returns null if a time is missing or invalid.
        $starts = self::readTime($input, 'startsAt', $errors);
        $ends = self::readTime($input, 'endsAt', $errors);

        //If both times are valid, check them against each other.
        if ($starts !== null && $ends !== null) {
            $seconds = $ends->getTimestamp() - $starts->getTimestamp();   // length of the window
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
            // Save in the database's format: 2026-11-20 19:00:00 (UTC).
            $data['startsAt'] = $starts->format('Y-m-d H:i:s');
        }
        if ($ends !== null) {
            $data['endsAt'] = $ends->format('Y-m-d H:i:s');
        }

        //capacity: required, whole number, 1 to 1000.
        if (!array_key_exists('capacity', $input)) {
            $errors['capacity'] = 'capacity is required.';
        } elseif (!is_int($input['capacity']) || $input['capacity'] < 1 || $input['capacity'] > self::MAX_CAPACITY) {
            // rejected instead of being quietly turned into a number.
            $errors['capacity'] = 'capacity must be a whole number from 1 to ' . self::MAX_CAPACITY . '.';
        } else {
            $data['capacity'] = $input['capacity'];
        }
        if (array_key_exists('isActive', $input)) {
            if (!is_bool($input['isActive'])) {
                $errors['isActive'] = 'isActive must be true or false.';
            } else {
                $data['isActive'] = $input['isActive'];
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /**
     * True if a UTC time (database format, e.g. '2026-11-20 19:00:00') is
     * later than right now.
     */
    public static function isFuture(string $utc): bool
    {
        $zone = new DateTimeZone('UTC');
        return new DateTimeImmutable($utc, $zone) > new DateTimeImmutable('now', $zone);
    }

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

        $normalised = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        if (strlen($normalised) === 22) {   // 22 characters = the version without seconds
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

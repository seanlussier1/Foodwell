<?php
declare(strict_types=1);

namespace Foodwell\Http;

final class Negotiation
{
    public const JSON = 'application/json';
    public const XML = 'application/xml';
    public const YAML = 'application/yaml';

    /** Supported media types for each serializer, in server preference order. */
    private const ALIASES = [
        self::JSON => [self::JSON],
        self::XML => [self::XML, 'text/xml'],
        self::YAML => [self::YAML, 'application/x-yaml', 'text/yaml', 'text/x-yaml'],
    ];

    public static function choose(string $accept, array $available): ?string
    {
        $ranges = self::parse($accept);
        if ($ranges === []) {
            return $available[0]; // no usable preference -> default format
        }

        $best = null;
        $bestQ = 0.0;
        $bestPosition = PHP_INT_MAX;
        foreach ($available as $type) {
            foreach (self::ALIASES[$type] ?? [$type] as $name) {
                [$q, $position] = self::quality($name, $ranges);
                if ($q > $bestQ || ($q === $bestQ && $q > 0 && $position < $bestPosition)) {
                    $best = $name;
                    $bestQ = $q;
                    $bestPosition = $position;
                }
            }
        }
        return $best;
    }

    private static function parse(string $accept): array
    {
        $ranges = [];
        foreach (explode(',', $accept) as $position => $entry) {
            $parts = explode(';', $entry);
            $media = strtolower(trim(array_shift($parts)));
            if (
                !preg_match('#^([a-z0-9!\#$&^_.+-]+|\*)/([a-z0-9!\#$&^_.+-]+|\*)$#', $media, $m)
                || ($m[1] === '*' && $m[2] !== '*')
            ) { // "*/json" is not a valid range
                continue;
            }

            $q = 1.0;
            foreach ($parts as $param) {
                [$name, $value] = array_pad(explode('=', $param, 2), 2, '');
                if (strtolower(trim($name)) !== 'q') {
                    continue; // other parameters (e.g. charset) don't affect the choice
                }
                $value = trim($value);
                if (!preg_match('/^(0(\.\d{0,3})?|1(\.0{0,3})?)$/', $value)) {
                    continue 2; // invalid q -> ignore the whole entry
                }
                $q = (float) $value;
            }
            $ranges[] = [$m[1], $m[2], $q, $position];
        }
        return $ranges;
    }
    public static function format(string $mediaType): string
    {
        foreach (self::ALIASES as $format => $names) {
            if (in_array($mediaType, $names, true)) return $format;
        }
        return $mediaType;
    }

    private static function quality(string $name, array $ranges): array
    {
        $bestSpecificity = -1;
        $result = [0.0, PHP_INT_MAX];

        [$main, $sub] = explode('/', $name, 2);
        foreach ($ranges as [$rMain, $rSub, $q, $position]) {
            $specificity = match (true) {
                $rMain === $main && $rSub === $sub => 2,   // exact: application/xml
                $rMain === $main && $rSub === '*' => 1,   // application/*
                $rMain === '*' => 0,   // */*
                default => -1,  // no match
            };
            if ($specificity > $bestSpecificity) {
                $bestSpecificity = $specificity;
                $result = [$q, $position];
            }
        }
        return $result;
    }
}

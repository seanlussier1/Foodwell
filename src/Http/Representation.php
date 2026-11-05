<?php
declare(strict_types=1);

namespace Foodwell\Http;

use XMLWriter;

final class Representation
{
    private static function isList(array $value): bool
    {
        $expected = 0;
        foreach ($value as $key => $_) {
            if ($key !== $expected++) return false;
        }
        return true;
    }

    public static function xml(mixed $payload, string $root, string $listItem = 'item'): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        self::xmlElement($writer, $root, $payload, $listItem);
        $writer->endDocument();
        return $writer->outputMemory();
    }

    private static function xmlElement(XMLWriter $writer, string $name, mixed $value, string $listItem): void
    {
        // Keys from user input may not be valid
        if (self::isXmlName($name)) {
            $writer->startElement($name);
        } else {
            $writer->startElement('entry');
            $writer->writeAttribute('key', self::xmlText($name));
        }

        if ($value === null) {
            $writer->writeAttribute('nil', 'true');          // JSON null
        } elseif (is_bool($value)) {
            $writer->text($value ? 'true' : 'false');
        } elseif (is_array($value)) {
            $isList = self::isList($value);
            foreach ($value as $key => $child) {
                self::xmlElement($writer, $isList ? $listItem : (string) $key, $child, $listItem);
            }
        } else {
            $writer->text(self::xmlText((string) $value));
        }
        $writer->endElement();
    }

    private static function isXmlName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/', $name) === 1
            && stripos($name, 'xml') !== 0; // names starting with "xml" are reserved
    }

    private static function xmlText(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $text);
    }

    public static function yaml(array $payload): string
    {
        if ($payload === []) {
            return (self::isList($payload) ? '[]' : '{}') . "\n";
        }
        return implode("\n", self::yamlLines($payload, 0)) . "\n";
    }

    private static function yamlLines(array $data, int $level): array
    {
        $pad = str_repeat('  ', $level);
        $isList = self::isList($data);
        $lines = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && $value !== []) {
                $child = self::yamlLines($value, $level + 1);
                if ($isList) {
                    // "- " goes in front of the item's first line; the rest stays aligned under it.
                    $child[0] = $pad . '- ' . ltrim($child[0]);
                } else {
                    $lines[] = $pad . self::yamlKey((string) $key) . ':';
                }
                array_push($lines, ...$child);
            } else {
                $lines[] = $isList
                    ? $pad . '- ' . self::yamlScalar($value)
                    : $pad . self::yamlKey((string) $key) . ': ' . self::yamlScalar($value);
            }
        }
        return $lines;
    }

    private static function yamlKey(string $key): string
    {
        $reserved = ['null', 'true', 'false', 'yes', 'no', 'on', 'off', 'y', 'n', '~'];
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1 && !in_array(strtolower($key), $reserved, true)
            ? $key
            : self::yamlScalar($key); // quote anything unusual
    }

    private static function yamlScalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => (string) json_encode($value),
            is_array($value) => self::isList($value) ? '[]' : '{}', // only empty arrays reach here
            // A JSON string is also a valid YAML double-quoted string, with the same escapes.
            default => (string) json_encode(
                mb_scrub((string) $value, 'UTF-8'),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        };
    }
}

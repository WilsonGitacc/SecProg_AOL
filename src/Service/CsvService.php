<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Formula-Injection-Safe CSV Export & Validated Import Service.
 */
final class CsvService
{
    private const TRIGGER_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitizeCell(mixed $value): string
    {
        $str = (string) $value;
        if ($str === '') {
            return '';
        }
        if (in_array($str[0], self::TRIGGER_CHARS, true)) {
            return "'" . $str;
        }
        return $str;
    }

    public static function serializeRow(array $row): string
    {
        $cells = array_map(static function (mixed $cell): string {
            $safe = self::sanitizeCell($cell);
            if (strpbrk($safe, ",\"\n\r") !== false) {
                return '"' . str_replace('"', '""', $safe) . '"';
            }
            return $safe;
        }, $row);
        return implode(',', $cells);
    }

    public static function exportTable(array $headers, array $rows): string
    {
        $lines = [self::serializeRow($headers)];
        foreach ($rows as $row) {
            $lines[] = self::serializeRow($row);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Validates and parses a CSV string for grade or enrollment import.
     * @return array{valid: bool, errors: array<int, string>, rows: array<int, array<string, string>>}
     */
    public static function parseAndValidate(string $csvContent, array $expectedHeaders, int $maxRows = 500): array
    {
        if (!mb_check_encoding($csvContent, 'UTF-8')) {
            return ['valid' => false, 'errors' => ['CSV file must be valid UTF-8 encoded.'], 'rows' => []];
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($csvContent)) ?: [];
        if (count($lines) < 2) {
            return ['valid' => false, 'errors' => ['CSV must contain a header row and at least one data row.'], 'rows' => []];
        }
        if (count($lines) - 1 > $maxRows) {
            return ['valid' => false, 'errors' => ["CSV exceeds maximum row cap ({$maxRows} rows)."], 'rows' => []];
        }

        $headerLine = str_getcsv((string) array_shift($lines));
        $normalizedHeaders = array_map(static fn ($h) => strtolower(trim((string) $h)), $headerLine);
        $expectedNorm = array_map(static fn ($h) => strtolower(trim((string) $h)), $expectedHeaders);

        if ($normalizedHeaders !== $expectedNorm) {
            return [
                'valid' => false,
                'errors' => ['CSV header mismatch. Expected: ' . implode(', ', $expectedHeaders)],
                'rows' => [],
            ];
        }

        $parsedRows = [];
        $errors = [];
        foreach ($lines as $idx => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if (count($cols) !== count($expectedNorm)) {
                $errors[] = 'Row ' . ($idx + 2) . ': column count mismatch.';
                continue;
            }
            $assoc = [];
            foreach ($expectedNorm as $cIdx => $colName) {
                $assoc[$colName] = trim((string) $cols[$cIdx]);
            }
            $parsedRows[] = $assoc;
        }

        return ['valid' => empty($errors), 'errors' => $errors, 'rows' => $parsedRows];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * CSV Formula Injection Sanitizer Model derived from:
 * - ORIGINAL_REQUEST.md §R4, §R5
 * - spec_report.md §11.3 (CSV Formula Injection Defense)
 */
class CsvFormulaSanitizerModel
{
    private const TRIGGER_CHARACTERS = ['=', '+', '-', '@', "\t", "\r"];

    public static function sanitizeCell(mixed $value): string
    {
        $str = (string) $value;

        if ($str === '') {
            return '';
        }

        $firstChar = $str[0];
        if (in_array($firstChar, self::TRIGGER_CHARACTERS, true)) {
            return "'" . $str;
        }

        return $str;
    }

    public static function serializeRow(array $row): string
    {
        $sanitizedCells = array_map(function ($cell) {
            $sanitized = self::sanitizeCell($cell);
            // RFC 4180 escaping: wrap in quotes if contains comma, quote, or newline
            if (strpbrk($sanitized, ",\"\n\r") !== false) {
                return '"' . str_replace('"', '""', $sanitized) . '"';
            }
            return $sanitized;
        }, $row);

        return implode(',', $sanitizedCells);
    }
}

class CsvFormulaInjectionTest extends TestCase
{
    /**
     * @test
     * Verifies that cells beginning with formula trigger characters (=, +, -, @, \t, \r) are prefixed with '.
     */
    public function testFormulaPrefixCharactersAreNeutralized(): void
    {
        $payloads = [
            '=cmd|\'/C calc\'!A0'         => '\'=cmd|\'/C calc\'!A0',
            '+1+2'                         => '\'+1+2',
            '-SUM(A1:A10)'                 => '\'-SUM(A1:A10)',
            '@SUM(A1:A10)'                 => '\'@SUM(A1:A10)',
            "\t=1+2"                       => "'\t=1+2",
            "\r=1+2"                       => "'\r=1+2",
            '=HYPERLINK("http://evil.com")'=> '\'=HYPERLINK("http://evil.com")',
        ];

        foreach ($payloads as $dangerousPayload => $expectedSanitized) {
            $sanitized = CsvFormulaSanitizerModel::sanitizeCell($dangerousPayload);
            $this->assertSame(
                $expectedSanitized,
                $sanitized,
                sprintf("Payload [%s] was not correctly neutralized.", $dangerousPayload)
            );
            $this->assertStringStartsWith("'", $sanitized);
        }
    }

    /**
     * @test
     * Verifies that normal academic strings without formula triggers are not modified.
     */
    public function testNormalDataIsPreservedWithoutAlteration(): void
    {
        $safeCells = [
            'John Doe' => 'John Doe',
            'COMP6695001' => 'COMP6695001',
            'Secure Programming' => 'Secure Programming',
            '85.5' => '85.5',
            'Present' => 'Present',
            '' => '',
        ];

        foreach ($safeCells as $original => $expected) {
            $sanitized = CsvFormulaSanitizerModel::sanitizeCell($original);
            $this->assertSame($expected, $sanitized, "Safe value [{$original}] should remain unchanged.");
        }
    }

    /**
     * @test
     * Verifies that CSV rows containing formula injection attempts are safely serialized.
     */
    public function testCsvRowSerializationSafeguard(): void
    {
        $row = [
            'NIM2601001',
            'Alice Smith',
            '=1+1',
            'Passed',
        ];

        $serialized = CsvFormulaSanitizerModel::serializeRow($row);
        $expected = "NIM2601001,Alice Smith,'=1+1,Passed";

        $this->assertSame($expected, $serialized, "Row must neutralize formula cell in serialized output.");
    }
}

<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * Safe File Upload Pipeline Validator Model derived from:
 * - ORIGINAL_REQUEST.md §R5
 * - spec_report.md §11 (Safe File Upload & Export Security Pipeline)
 */
class UploadValidatorModel
{
    public const MAX_SIZE_BYTES = 10_485_760; // 10 MB

    public const ALLOWED_EXTENSIONS = [
        'pdf',
        'png',
        'jpg',
        'jpeg',
        'txt',
        'zip',
    ];

    public const ALLOWED_MIME_TYPES = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'txt'  => ['text/plain'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];

    public static function validate(
        string $originalFilename,
        int $fileSizeBytes,
        string $detectedMimeType
    ): array {
        // 1. Null-byte rejection
        if (str_contains($originalFilename, "\0")) {
            return ['valid' => false, 'error' => 'Null-byte injection detected.'];
        }

        // 2. Extension extraction and normalization
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ['valid' => false, 'error' => "Extension [{$extension}] is not permitted."];
        }

        // 3. Size cap
        if ($fileSizeBytes > self::MAX_SIZE_BYTES) {
            return ['valid' => false, 'error' => 'File size exceeds maximum 10MB limit.'];
        }

        if ($fileSizeBytes <= 0) {
            return ['valid' => false, 'error' => 'Empty files are not allowed.'];
        }

        // 4. MIME type inspection
        $allowedMimes = self::ALLOWED_MIME_TYPES[$extension] ?? [];
        if (!in_array($detectedMimeType, $allowedMimes, true)) {
            return ['valid' => false, 'error' => "MIME type [{$detectedMimeType}] does not match extension [{$extension}]."];
        }

        // 5. Generate secure UUIDv4 storage name
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // set version to 0100
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // set bits 6-7 to 10
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        return [
            'valid' => true,
            'storage_filename' => "{$uuid}.{$extension}",
            'sanitized_original_name' => preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $originalFilename),
        ];
    }
}

class SafeUploadPipelineTest extends TestCase
{
    /**
     * @test
     * Verifies that legitimate files within the allowlist and size limit pass validation.
     */
    public function testLegitimateFilesPassValidation(): void
    {
        $validFiles = [
            ['assignment_submission.pdf', 1024 * 500, 'application/pdf'],
            ['diagram.png', 1024 * 200, 'image/png'],
            ['photo.jpg', 1024 * 300, 'image/jpeg'],
            ['notes.txt', 1024 * 10, 'text/plain'],
            ['project_code.zip', 1024 * 1024 * 2, 'application/zip'],
        ];

        foreach ($validFiles as [$filename, $size, $mime]) {
            $result = UploadValidatorModel::validate($filename, $size, $mime);
            $this->assertTrue($result['valid'], "Legitimate file [{$filename}] should pass validation.");
            $this->assertNotEmpty($result['storage_filename']);
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.[a-z0-9]+$/',
                $result['storage_filename'],
                "Storage name must follow UUIDv4 format."
            );
        }
    }

    /**
     * @test
     * Verifies that dangerous executable extensions are strictly rejected.
     */
    public function testDangerousExtensionsAreRejected(): void
    {
        $dangerousFilenames = [
            'shell.php',
            'exploit.phtml',
            'malware.phar',
            'script.sh',
            'program.exe',
            'page.html',
            'page.htm',
            'vector.svg',
            'test.php5',
            'config.cgi',
        ];

        foreach ($dangerousFilenames as $badFilename) {
            $result = UploadValidatorModel::validate($badFilename, 1024, 'text/plain');
            $this->assertFalse(
                $result['valid'],
                "Dangerous file [{$badFilename}] must be rejected."
            );
        }
    }

    /**
     * @test
     * Verifies that extension checks are case-insensitive (.PHP, .pHP, .PhP rejected).
     */
    public function testCaseInsensitiveExtensionBypassPrevented(): void
    {
        $mixedCasePhp = ['shell.PHP', 'shell.pHP', 'shell.PhP', 'page.HTML'];

        foreach ($mixedCasePhp as $filename) {
            $result = UploadValidatorModel::validate($filename, 1024, 'text/plain');
            $this->assertFalse(
                $result['valid'],
                "Mixed-case dangerous file [{$filename}] must be rejected."
            );
        }
    }

    /**
     * @test
     * Verifies that null-byte injection attempts are blocked.
     */
    public function testNullByteInjectionPrevented(): void
    {
        $nullByteFilename = "shell.php\0.png";
        $result = UploadValidatorModel::validate($nullByteFilename, 1024, 'image/png');

        $this->assertFalse(
            $result['valid'],
            "Null-byte injection in filename must be rejected."
        );
    }

    /**
     * @test
     * Verifies that MIME type spoofing is detected and rejected.
     */
    public function testMimeTypeSpoofingDetected(): void
    {
        // Disguised file claiming to be png, but actually text/plain or PHP
        $result = UploadValidatorModel::validate('avatar.png', 1024, 'text/plain');
        $this->assertFalse($result['valid'], "MIME mismatch (png with text/plain) must be rejected.");

        // Disguised PDF with text/html MIME
        $resultHtmlInPdf = UploadValidatorModel::validate('document.pdf', 1024, 'text/html');
        $this->assertFalse($resultHtmlInPdf['valid'], "MIME mismatch (pdf with text/html) must be rejected.");
    }

    /**
     * @test
     * Verifies that files exceeding 10MB are strictly rejected.
     */
    public function testFilesExceeding10MbAreRejected(): void
    {
        // 10 MB exact (10,485,760 bytes) -> Allowed
        $exactLimitResult = UploadValidatorModel::validate(
            'archive.zip',
            UploadValidatorModel::MAX_SIZE_BYTES,
            'application/zip'
        );
        $this->assertTrue($exactLimitResult['valid'], "File at exact 10MB limit must be allowed.");

        // 10 MB + 1 byte (10,485,761 bytes) -> Rejected
        $overLimitResult = UploadValidatorModel::validate(
            'archive.zip',
            UploadValidatorModel::MAX_SIZE_BYTES + 1,
            'application/zip'
        );
        $this->assertFalse($overLimitResult['valid'], "File exceeding 10MB by 1 byte must be rejected.");
    }
}

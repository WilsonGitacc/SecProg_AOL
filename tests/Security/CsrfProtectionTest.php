<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * In-memory reference CSRF token validator model derived from:
 * - ORIGINAL_REQUEST.md §R5
 * - spec_report.md §10.4 (Cross-Site Request Forgery Defense)
 */
class CsrfValidatorModel
{
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function validate(
        string $method,
        ?string $submittedToken,
        ?string $sessionToken
    ): bool {
        $method = strtoupper($method);

        // Safe methods do not require CSRF validation
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        // State changing methods (POST, PUT, DELETE, PATCH)
        if ($submittedToken === null || $sessionToken === null) {
            return false;
        }

        if (strlen($submittedToken) === 0 || strlen($sessionToken) === 0) {
            return false;
        }

        // Timing-attack safe comparison
        return hash_equals($sessionToken, $submittedToken);
    }
}

class CsrfProtectionTest extends TestCase
{
    private string $validSessionToken;

    protected function setUp(): void
    {
        $this->validSessionToken = CsrfValidatorModel::generateToken();
    }

    /**
     * @test
     * Verifies that generated CSRF tokens are 64-character hexadecimal strings with 256 bits of entropy.
     */
    public function testCsrfTokenEntropyAndLength(): void
    {
        $token = CsrfValidatorModel::generateToken();

        $this->assertSame(64, strlen($token), "CSRF token must be 64 hexadecimal characters (32 bytes).");
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token, "CSRF token must match hex pattern.");

        // Uniqueness check: two sequential tokens must never collide
        $secondToken = CsrfValidatorModel::generateToken();
        $this->assertNotEquals($token, $secondToken, "Sequential CSRF tokens must be distinct.");
    }

    /**
     * @test
     * Verifies that valid matching token is accepted on POST, PUT, DELETE, PATCH.
     */
    public function testValidTokenPassesOnStateChangingMethods(): void
    {
        $methods = ['POST', 'PUT', 'DELETE', 'PATCH'];

        foreach ($methods as $method) {
            $isValid = CsrfValidatorModel::validate(
                $method,
                $this->validSessionToken,
                $this->validSessionToken
            );
            $this->assertTrue($isValid, "Valid CSRF token must be accepted for method {$method}.");
        }
    }

    /**
     * @test
     * Verifies that safe HTTP methods (GET, HEAD, OPTIONS) do not require CSRF token.
     */
    public function testSafeMethodsDoNotRequireCsrfToken(): void
    {
        $safeMethods = ['GET', 'HEAD', 'OPTIONS'];

        foreach ($safeMethods as $method) {
            $isValidWithoutToken = CsrfValidatorModel::validate(
                $method,
                null,
                $this->validSessionToken
            );
            $this->assertTrue($isValidWithoutToken, "Safe method {$method} must not require CSRF token.");
        }
    }

    /**
     * @test
     * Verifies that missing CSRF token on POST is rejected.
     */
    public function testMissingTokenIsRejectedOnPost(): void
    {
        $isMissingRejected = CsrfValidatorModel::validate(
            'POST',
            null,
            $this->validSessionToken
        );
        $this->assertFalse($isMissingRejected, "POST without CSRF token must be rejected (false).");

        $isEmptyRejected = CsrfValidatorModel::validate(
            'POST',
            '',
            $this->validSessionToken
        );
        $this->assertFalse($isEmptyRejected, "POST with empty CSRF token must be rejected (false).");
    }

    /**
     * @test
     * Verifies that a forged token or token from another user session is rejected.
     */
    public function testForgedOrCrossSessionTokenIsRejected(): void
    {
        $otherSessionToken = CsrfValidatorModel::generateToken();

        // Cross-session token submission
        $isCrossSessionRejected = CsrfValidatorModel::validate(
            'POST',
            $otherSessionToken,
            $this->validSessionToken
        );
        $this->assertFalse($isCrossSessionRejected, "CSRF token from another session must be rejected.");

        // Tampered token (single character flipped)
        $tamperedToken = substr($this->validSessionToken, 0, -1) . ($this->validSessionToken[-1] === 'a' ? 'b' : 'a');
        $isTamperedRejected = CsrfValidatorModel::validate(
            'POST',
            $tamperedToken,
            $this->validSessionToken
        );
        $this->assertFalse($isTamperedRejected, "Tampered CSRF token must be rejected.");
    }

    /**
     * @test
     * Verifies timing-attack safe comparison via hash_equals.
     */
    public function testTimingSafeComparison(): void
    {
        $known = $this->validSessionToken;
        $user = $this->validSessionToken;

        $this->assertTrue(
            hash_equals($known, $user),
            "hash_equals must return true for identical tokens."
        );

        $forged = str_repeat('0', 64);
        $this->assertFalse(
            hash_equals($known, $forged),
            "hash_equals must return false for non-matching tokens."
        );
    }
}

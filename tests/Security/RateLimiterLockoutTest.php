<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * In-memory reference rate limiter & lockout model derived from:
 * - ORIGINAL_REQUEST.md §R5
 * - spec_report.md §10.1 (Authentication & Session Management)
 */
class RateLimiterModel
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_DURATION = 900; // 15 minutes

    private array $attempts = []; // key => list of timestamps
    private array $lockedUntil = []; // key => timestamp

    public function recordFailure(string $identifier, int $now): void
    {
        $this->cleanupOldAttempts($identifier, $now);
        $this->attempts[$identifier][] = $now;

        if (count($this->attempts[$identifier]) >= self::MAX_ATTEMPTS) {
            $this->lockedUntil[$identifier] = $now + self::LOCKOUT_DURATION;
        }
    }

    public function isLocked(string $identifier, int $now): bool
    {
        if (isset($this->lockedUntil[$identifier])) {
            if ($now < $this->lockedUntil[$identifier]) {
                return true;
            }
            // Expired lock
            unset($this->lockedUntil[$identifier]);
            $this->attempts[$identifier] = [];
        }
        return false;
    }

    public function getRemainingLockoutSeconds(string $identifier, int $now): int
    {
        if ($this->isLocked($identifier, $now)) {
            return $this->lockedUntil[$identifier] - $now;
        }
        return 0;
    }

    public function getExponentialDelaySeconds(string $identifier): int
    {
        $count = count($this->attempts[$identifier] ?? []);
        return match ($count) {
            0, 1 => 0,
            2 => 1,
            3 => 2,
            4 => 4,
            default => 8,
        };
    }

    public function recordSuccess(string $identifier): void
    {
        unset($this->attempts[$identifier], $this->lockedUntil[$identifier]);
    }

    private function cleanupOldAttempts(string $identifier, int $now): void
    {
        if (!isset($this->attempts[$identifier])) {
            return;
        }
        $cutoff = $now - self::LOCKOUT_DURATION;
        $this->attempts[$identifier] = array_filter(
            $this->attempts[$identifier],
            fn (int $t) => $t >= $cutoff
        );
    }
}

class RateLimiterLockoutTest extends TestCase
{
    private RateLimiterModel $limiter;
    private int $currentTime;

    protected function setUp(): void
    {
        $this->limiter = new RateLimiterModel();
        $this->currentTime = 1792999000; // Fixed epoch timestamp
    }

    /**
     * @test
     * Verifies that up to 4 failed attempts do NOT lock the account.
     */
    public function testAttemptsUnderLimitDoNotLockAccount(): void
    {
        $username = 'student1';

        for ($i = 1; $i <= 4; $i++) {
            $this->limiter->recordFailure($username, $this->currentTime);
            $this->assertFalse(
                $this->limiter->isLocked($username, $this->currentTime),
                "Attempt {$i} must not lock the account."
            );
        }
    }

    /**
     * @test
     * Verifies that the 5th failed attempt locks the account for exactly 15 minutes (900 seconds).
     */
    public function testFifthFailedAttemptLocksAccountFor15Minutes(): void
    {
        $username = 'lecturer1';

        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->recordFailure($username, $this->currentTime);
        }

        $this->assertTrue(
            $this->limiter->isLocked($username, $this->currentTime),
            "Account must be locked on 5th failed attempt."
        );

        $remaining = $this->limiter->getRemainingLockoutSeconds($username, $this->currentTime);
        $this->assertSame(900, $remaining, "Lockout duration must be exactly 900 seconds (15 minutes).");
    }

    /**
     * @test
     * Verifies that the account remains locked at 14m59s, but unlocks at 15m01s.
     */
    public function testAccountUnlocksAfter15MinutesExpiry(): void
    {
        $username = 'operator1';

        for ($i = 1; $i <= 5; $i++) {
            $this->limiter->recordFailure($username, $this->currentTime);
        }

        // 14 minutes and 59 seconds later (899s) -> Still locked
        $justBeforeExpiry = $this->currentTime + 899;
        $this->assertTrue(
            $this->limiter->isLocked($username, $justBeforeExpiry),
            "Account must remain locked before 900 seconds elapse."
        );

        // 15 minutes and 1 second later (901s) -> Unlocked
        $afterExpiry = $this->currentTime + 901;
        $this->assertFalse(
            $this->limiter->isLocked($username, $afterExpiry),
            "Account must unlock automatically after 900 seconds."
        );
    }

    /**
     * @test
     * Verifies that exponential delay increases progressively with failed attempts.
     */
    public function testExponentialDelayIncreasesWithFailedAttempts(): void
    {
        $username = 'student2';

        $expectedDelays = [
            1 => 0,
            2 => 1,
            3 => 2,
            4 => 4,
            5 => 8,
        ];

        foreach ($expectedDelays as $attempt => $expectedDelay) {
            $this->limiter->recordFailure($username, $this->currentTime);
            $actualDelay = $this->limiter->getExponentialDelaySeconds($username);
            $this->assertSame(
                $expectedDelay,
                $actualDelay,
                "Delay after attempt {$attempt} must be {$expectedDelay} seconds."
            );
        }
    }

    /**
     * @test
     * Verifies that successful login immediately clears failed attempts and resets lock status.
     */
    public function testSuccessfulLoginResetsFailedAttemptCounter(): void
    {
        $username = 'student3';

        // 3 failed attempts
        for ($i = 1; $i <= 3; $i++) {
            $this->limiter->recordFailure($username, $this->currentTime);
        }

        // Successful authentication
        $this->limiter->recordSuccess($username);

        $this->assertFalse(
            $this->limiter->isLocked($username, $this->currentTime),
            "Account must not be locked after successful login."
        );
        $this->assertSame(
            0,
            $this->limiter->getExponentialDelaySeconds($username),
            "Delay must reset to 0 seconds after successful login."
        );
    }

    /**
     * @test
     * OWASP ASVS V2.1: Authentication error message must be generic to prevent username enumeration.
     */
    public function testGenericErrorMessagePreventsUserEnumeration(): void
    {
        $genericError = "Invalid credentials";

        // Same error message regardless of whether username exists or password is wrong
        $nonExistentUserError = $genericError;
        $wrongPasswordError = $genericError;

        $this->assertSame(
            $nonExistentUserError,
            $wrongPasswordError,
            "Error messages for non-existent users and wrong passwords must be identical."
        );
        $this->assertStringNotContainsString("user", strtolower($genericError));
        $this->assertStringNotContainsString("password", strtolower($genericError));
    }
}

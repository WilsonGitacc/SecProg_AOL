<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * OWASP ASVS v4.0.3 V2 Authentication & Password Storage
 * Requirement R5 & Project Specification:
 * - Hashing must use PASSWORD_ARGON2ID
 * - Memory cost: 65536 (64 MB)
 * - Time cost: 4
 * - Threads: 1
 */
class Argon2idAuthTest extends TestCase
{
    private array $argon2idOptions = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
    ];

    /**
     * @test
     * Verifies that the PHP environment supports Argon2id hashing.
     */
    public function testArgon2idIsAvailable(): void
    {
        $this->assertTrue(
            defined('PASSWORD_ARGON2ID'),
            "PASSWORD_ARGON2ID constant must be defined in PHP runtime."
        );
    }

    /**
     * @test
     * Verifies that passwords are hashed using Argon2id with exact ASVS L2 cost parameters.
     */
    public function testPasswordHashedWithArgon2idParameters(): void
    {
        $password = 'SuperSecureP@ssw0rd!2026';
        $hash = password_hash($password, PASSWORD_ARGON2ID, $this->argon2idOptions);

        $this->assertNotEmpty($hash, "Hash output must not be empty.");
        $this->assertStringStartsWith('$argon2id$', $hash, 'Hash must start with Argon2id signature ($argon2id$).');

        $info = password_get_info($hash);
        $this->assertSame(
            PASSWORD_ARGON2ID,
            $info['algo'],
            "Algorithm in password_get_info must match PASSWORD_ARGON2ID."
        );
        $this->assertSame(65536, $info['options']['memory_cost'], "Memory cost must be 65536 KiB (64 MB).");
        $this->assertSame(4, $info['options']['time_cost'], "Time cost must be 4.");
        $this->assertSame(1, $info['options']['threads'], "Thread count must be 1.");
    }

    /**
     * @test
     * Verifies that password_verify succeeds with the exact password and fails with incorrect passwords.
     */
    public function testPasswordVerificationSucceedsAndFailsCorrectly(): void
    {
        $password = 'CorrectHorseBatteryStaple#2026';
        $hash = password_hash($password, PASSWORD_ARGON2ID, $this->argon2idOptions);

        // Valid password
        $this->assertTrue(
            password_verify($password, $hash),
            "password_verify must return true for exact matching password."
        );

        // Invalid passwords
        $this->assertFalse(
            password_verify('WrongPassword123!', $hash),
            "password_verify must return false for wrong password."
        );
        $this->assertFalse(
            password_verify($password . 'extra', $hash),
            "password_verify must return false for password with appended characters."
        );
        $this->assertFalse(
            password_verify(strtolower($password), $hash),
            "password_verify must be case-sensitive."
        );
        $this->assertFalse(
            password_verify('', $hash),
            "password_verify must return false for empty string."
        );
    }

    /**
     * @test
     * Verifies that rehashing is detected when cost parameters are upgraded.
     */
    public function testPasswordNeedsRehashDetection(): void
    {
        // Hash with lower time cost
        $oldOptions = ['memory_cost' => 65536, 'time_cost' => 2, 'threads' => 1];
        $oldHash = password_hash('UserPassword#2026', PASSWORD_ARGON2ID, $oldOptions);

        // Check if rehash is needed against current target parameters (time_cost = 4)
        $needsRehash = password_needs_rehash($oldHash, PASSWORD_ARGON2ID, $this->argon2idOptions);
        $this->assertTrue($needsRehash, "password_needs_rehash must return true when cost options have increased.");

        // Hash with current target options
        $currentHash = password_hash('UserPassword#2026', PASSWORD_ARGON2ID, $this->argon2idOptions);
        $this->assertFalse(
            password_needs_rehash($currentHash, PASSWORD_ARGON2ID, $this->argon2idOptions),
            "password_needs_rehash must return false when hash already matches target parameters."
        );
    }

    /**
     * @test
     * Verifies that complex UTF-8 characters and high-entropy passwords are preserved and hashed accurately.
     */
    public function testUnicodeAndSpecialCharactersInPassword(): void
    {
        $unicodePassword = 'KunciRahasia!🔒🔑_Pássw0rd_日本語';
        $hash = password_hash($unicodePassword, PASSWORD_ARGON2ID, $this->argon2idOptions);

        $this->assertTrue(
            password_verify($unicodePassword, $hash),
            "Argon2id must correctly verify passwords containing complex UTF-8 characters and emojis."
        );
    }
}

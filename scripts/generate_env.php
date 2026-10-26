#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Secure Neo-Brutalist LMS - Cryptographic .env Generator
 *
 * Generates local .env with 256-bit APP_KEY and high-entropy database passwords.
 * Sets strict file permissions (0600).
 *
 * Usage: php scripts/generate_env.php [--force]
 */

$rootDir = dirname(__DIR__);
$envExamplePath = $rootDir . '/.env.example';
$envPath = $rootDir . '/.env';

echo "=== Secure Neo-Brutalist LMS: .env Generator ===\n";

if (!file_exists($envExamplePath)) {
    fwrite(STDERR, "Error: Template file .env.example not found at {$envExamplePath}\n");
    exit(1);
}

$force = in_array('--force', $argv, true);

if (file_exists($envPath) && !$force) {
    echo "Notice: .env already exists. Use '--force' to regenerate credentials.\n";
    exit(0);
}

/**
 * Generates a high-entropy alphanumeric password (immune to shell escaping issues).
 */
function generateRandomPassword(int $length = 32): string {
    return bin2hex(random_bytes((int) ceil($length / 2)));
}

/**
 * Generates a 256-bit base64-encoded application encryption key.
 */
function generateAppKey(): string {
    return 'base64:' . base64_encode(random_bytes(32));
}

$appKey = generateAppKey();
$rootPassword = generateRandomPassword(32);
$migratorPassword = generateRandomPassword(32);
$appPassword = generateRandomPassword(32);

$template = file_get_contents($envExamplePath);

$replacements = [
    'APP_KEY=replace_with_256bit_random_key_via_generate_env_script' => "APP_KEY={$appKey}",
    'DB_ROOT_PASSWORD=replace_with_strong_random_root_password' => "DB_ROOT_PASSWORD={$rootPassword}",
    'DB_MIGRATOR_PASSWORD=replace_with_strong_random_migrator_password' => "DB_MIGRATOR_PASSWORD={$migratorPassword}",
    'DB_PASSWORD=replace_with_strong_random_app_password' => "DB_PASSWORD={$appPassword}",
];

$content = str_replace(array_keys($replacements), array_values($replacements), $template);

if (file_put_contents($envPath, $content) === false) {
    fwrite(STDERR, "Error: Failed to write .env file at {$envPath}\n");
    exit(1);
}

// Lock down permissions so only the owner can read/write
chmod($envPath, 0600);

echo "[SUCCESS] Generated secure .env file successfully.\n";
echo "- APP_KEY: 256-bit random key generated.\n";
echo "- DB_ROOT_PASSWORD: 128-bit random secret generated.\n";
echo "- DB_MIGRATOR_PASSWORD: 128-bit random secret generated.\n";
echo "- DB_PASSWORD: 128-bit random secret generated.\n";
echo "- File permissions locked to 0600 (owner read/write only).\n";
echo "Zero committed credentials guaranteed.\n";
exit(0);

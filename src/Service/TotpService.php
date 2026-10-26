<?php

declare(strict_types=1);

namespace App\Service;

/**
 * RFC 6238 TOTP (Time-Based One-Time Password) Service.
 */
final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 16): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }
        return $secret;
    }

    public static function getCode(string $base32Secret, ?int $timeSlice = null): string
    {
        $timeSlice ??= (int) floor(time() / 30);
        $secretKey = self::base32Decode($base32Secret);
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hm = hash_hmac('sha1', $time, $secretKey, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashpart = substr($hm, $offset, 4);
        $value = unpack('N', $hashpart)[1] & 0x7FFFFFFF;
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verifyCode(string $base32Secret, string $code, int $discrepancy = 1): bool
    {
        $cleanCode = trim($code);
        if (!preg_match('/^\d{6}$/', $cleanCode)) {
            return false;
        }
        $currentTimeSlice = (int) floor(time() / 30);
        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $calculated = self::getCode($base32Secret, $currentTimeSlice + $i);
            if (hash_equals($calculated, $cleanCode)) {
                return true;
            }
        }
        return false;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?: '');
        $binaryString = '';
        for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
            $pos = strpos(self::ALPHABET, $secret[$i]);
            if ($pos !== false) {
                $binaryString .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $eightBits = str_split($binaryString, 8);
        $out = '';
        foreach ($eightBits as $bits) {
            if (strlen($bits) === 8) {
                $out .= chr((int) bindec($bits));
            }
        }
        return $out;
    }
}

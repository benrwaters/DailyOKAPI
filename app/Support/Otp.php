<?php

namespace App\Support;

class Otp
{
    public static function generate_numeric(int $length = 6): string
    {
        $min = (int) str_pad('1', $length, '0');
        $max = (int) str_pad('', $length, '9');

        return (string) random_int($min, $max);
    }

    public static function hash(string $otp): string
    {
        // bcrypt/argon is fine for short-lived OTPs
        return password_hash($otp, PASSWORD_BCRYPT);
    }

    public static function verify(string $otp, string $hash): bool
    {
        return password_verify($otp, $hash);
    }
}

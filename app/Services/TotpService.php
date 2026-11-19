<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * RFC 6238 TOTP (Time-based One-Time Password) & RFC 4648 Base32 implementation
 * compatible with Google Authenticator, Authy, 1Password, and Bitwarden.
 */
class TotpService
{
    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const int PERIOD = 30;

    private const int DIGITS = 6;

    public function generateSecret(int $length = 32): string
    {
        $bytes = random_bytes($length);
        $secret = '';

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[ord($bytes[$i]) % 32];
        }

        return $secret;
    }

    public function getOtpAuthUri(string $issuer, string $accountName, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    public function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timeSlice = (int) floor(($timestamp ?? time()) / self::PERIOD);

        return $this->hotp($secret, $timeSlice);
    }

    public function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $normalized)) {
            return false;
        }

        $currentSlice = (int) floor(($timestamp ?? time()) / self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidate = $this->hotp($secret, $currentSlice + $offset);
            if (hash_equals($candidate, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    private function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $truncated % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        $len = strlen($clean);
        for ($i = 0; $i < $len; $i++) {
            $val = strpos(self::BASE32_ALPHABET, $clean[$i]);
            if ($val === false) {
                continue;
            }

            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }

        return $output;
    }
}

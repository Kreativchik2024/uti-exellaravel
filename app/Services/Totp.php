<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;

/**
 * Одноразовые коды по времени (TOTP, RFC 6238) — как в Google Authenticator / Яндекс Ключ.
 * Работает без интернета: телефон и сервер считают код из общего секрета и текущего времени.
 * Важно: часы сервера должны быть точными (±30 с), см. «Состояние сервера».
 */
class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const WINDOW = 1;   // принимаем соседний 30-секундный интервал (небольшой сдвиг часов)
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $b) $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];

        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $num = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($num % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Проверка кода. Повторно один и тот же код не принимается (защита от подсмотренного кода):
     * запоминаем последний использованный интервал пользователя.
     */
    public static function verify(string $secret, string $code, int $userId): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) return false;

        $now = intdiv(now()->getTimestamp(), self::PERIOD);
        $lastUsed = (int) Cache::get("totp.last.$userId", 0);

        for ($i = -self::WINDOW; $i <= self::WINDOW; $i++) {
            $step = $now + $i;
            if ($step > $lastUsed && hash_equals(self::code($secret, $step), $code)) {
                Cache::put("totp.last.$userId", $step, now()->addMinutes(5));
                return true;
            }
        }

        return false;
    }

    /** Ссылка для приложения-аутентификатора (содержимое QR-кода). */
    public static function uri(string $secret, string $account): string
    {
        $issuer = config('app.name', 'УТИ');

        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&period=' . self::PERIOD . '&digits=' . self::DIGITS;
    }

    public static function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd()));

        return $writer->writeString($uri);
    }

    /** Резервные коды на случай потери телефона: 8 штук вида XXXX-XXXX. */
    public static function recoveryCodes(): array
    {
        return array_map(fn () => strtoupper(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))), range(1, 8));
    }

    private static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $out .= chr(bindec($byte));
        }

        return $out;
    }
}

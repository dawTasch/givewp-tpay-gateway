<?php

namespace OstojaTpayGiveWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Konfiguracja bramki.
 *
 * Kolejność źródeł: stałe w wp-config.php (zalecane), potem ustawienia GiveWP.
 * Sekrety nigdy nie są przekazywane do przeglądarki ani wypisywane w HTML.
 */
final class Config
{
    const OPT_SANDBOX = 'ostoja_tpay_sandbox';
    const OPT_CLIENT_ID = 'ostoja_tpay_client_id';
    const OPT_CLIENT_SECRET = 'ostoja_tpay_client_secret';
    const OPT_SECURITY_CODE = 'ostoja_tpay_security_code';

    const CONST_SANDBOX = 'OSTOJA_TPAY_SANDBOX';
    const CONST_CLIENT_ID = 'OSTOJA_TPAY_CLIENT_ID';
    const CONST_CLIENT_SECRET = 'OSTOJA_TPAY_CLIENT_SECRET';
    const CONST_SECURITY_CODE = 'OSTOJA_TPAY_SECURITY_CODE';

    public static function isSandbox(): bool
    {
        if (defined(self::CONST_SANDBOX)) {
            return (bool) constant(self::CONST_SANDBOX);
        }

        // Domyślnie Sandbox – bezpieczniej przy pierwszej instalacji.
        return self::option(self::OPT_SANDBOX, 'enabled') !== 'disabled';
    }

    public static function clientId(): string
    {
        return self::value(self::CONST_CLIENT_ID, self::OPT_CLIENT_ID);
    }

    public static function clientSecret(): string
    {
        return self::value(self::CONST_CLIENT_SECRET, self::OPT_CLIENT_SECRET);
    }

    /**
     * Kod bezpieczeństwa z Panelu Tpay (Powiadomienia → Bezpieczeństwo). Opcjonalny;
     * jeśli ustawiony, dodatkowo weryfikujemy sumę md5sum powiadomienia.
     */
    public static function securityCode(): string
    {
        return self::value(self::CONST_SECURITY_CODE, self::OPT_SECURITY_CODE);
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    public static function isFromConstant(string $constant): bool
    {
        return defined($constant) && trim((string) constant($constant)) !== '';
    }

    public static function apiBase(): string
    {
        return self::isSandbox() ? 'https://openapi.sandbox.tpay.com' : 'https://api.tpay.com';
    }

    /**
     * Host certyfikatów JWS – inny dla Sandbox i produkcji.
     */
    public static function certBase(): string
    {
        return self::isSandbox() ? 'https://secure.sandbox.tpay.com' : 'https://secure.tpay.com';
    }

    public static function envKey(): string
    {
        return self::isSandbox() ? 'sandbox' : 'live';
    }

    private static function value(string $constant, string $option): string
    {
        if (self::isFromConstant($constant)) {
            return trim((string) constant($constant));
        }

        return trim((string) self::option($option, ''));
    }

    private static function option(string $key, $default)
    {
        return function_exists('give_get_option') ? give_get_option($key, $default) : $default;
    }
}

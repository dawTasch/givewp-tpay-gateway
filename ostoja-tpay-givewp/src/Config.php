<?php

namespace OstojaTpayGiveWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Konfiguracja bramki.
 *
 * Środowisko (Sandbox/produkcja) wynika z „Trybu testowego” GiveWP
 * (Darowizny → Ustawienia → Bramki płatności). Dla każdego środowiska są osobne klucze.
 *
 * Kolejność źródeł kluczy: stałe w wp-config.php (zalecane), potem ustawienia GiveWP.
 * Sekrety nigdy nie są przekazywane do przeglądarki ani wypisywane w HTML.
 */
final class Config
{
    /** Klucze per środowisko: [stała produkcyjna, stała sandbox, opcja produkcyjna, opcja sandbox] */
    const KEYS = [
        'client_id' => ['OSTOJA_TPAY_CLIENT_ID', 'OSTOJA_TPAY_SANDBOX_CLIENT_ID', 'ostoja_tpay_client_id', 'ostoja_tpay_sandbox_client_id'],
        'client_secret' => ['OSTOJA_TPAY_CLIENT_SECRET', 'OSTOJA_TPAY_SANDBOX_CLIENT_SECRET', 'ostoja_tpay_client_secret', 'ostoja_tpay_sandbox_client_secret'],
        'security_code' => ['OSTOJA_TPAY_SECURITY_CODE', 'OSTOJA_TPAY_SANDBOX_SECURITY_CODE', 'ostoja_tpay_security_code', 'ostoja_tpay_sandbox_security_code'],
    ];

    /** Stała z wersji 0.1.x – obecnie ignorowana (środowisko wybiera Tryb testowy GiveWP). */
    const LEGACY_SANDBOX_CONSTANT = 'OSTOJA_TPAY_SANDBOX';

    /**
     * Bieżące środowisko dla NOWYCH płatności = Tryb testowy GiveWP.
     */
    public static function isSandbox(): bool
    {
        return function_exists('give_is_test_mode') && give_is_test_mode();
    }

    public static function clientId(bool $sandbox): string
    {
        return self::value('client_id', $sandbox);
    }

    public static function clientSecret(bool $sandbox): string
    {
        return self::value('client_secret', $sandbox);
    }

    /**
     * Kod bezpieczeństwa z Panelu Tpay (Powiadomienia → Bezpieczeństwo). Opcjonalny;
     * jeśli ustawiony, dodatkowo weryfikujemy sumę md5sum powiadomienia.
     */
    public static function securityCode(bool $sandbox): string
    {
        return self::value('security_code', $sandbox);
    }

    public static function isConfigured(bool $sandbox): bool
    {
        return self::clientId($sandbox) !== '' && self::clientSecret($sandbox) !== '';
    }

    /**
     * Czy dany klucz pochodzi ze stałej w wp-config.php.
     */
    public static function isFromConstant(string $key, bool $sandbox): bool
    {
        return self::constantValue(self::KEYS[$key][$sandbox ? 1 : 0]) !== '';
    }

    public static function constantName(string $key, bool $sandbox): string
    {
        return self::KEYS[$key][$sandbox ? 1 : 0];
    }

    public static function optionName(string $key, bool $sandbox): string
    {
        return self::KEYS[$key][$sandbox ? 3 : 2];
    }

    public static function apiBase(bool $sandbox): string
    {
        return $sandbox ? 'https://openapi.sandbox.tpay.com' : 'https://api.tpay.com';
    }

    /**
     * Host certyfikatów JWS – inny dla Sandbox i produkcji.
     */
    public static function certBase(bool $sandbox): string
    {
        return $sandbox ? 'https://secure.sandbox.tpay.com' : 'https://secure.tpay.com';
    }

    public static function label(bool $sandbox): string
    {
        return $sandbox ? 'Sandbox' : __('produkcja', 'ostoja-tpay-givewp');
    }

    private static function value(string $key, bool $sandbox): string
    {
        $fromConstant = self::constantValue(self::KEYS[$key][$sandbox ? 1 : 0]);
        if ($fromConstant !== '') {
            return $fromConstant;
        }
        $option = self::KEYS[$key][$sandbox ? 3 : 2];

        return function_exists('give_get_option') ? trim((string) give_get_option($option, '')) : '';
    }

    private static function constantValue(string $constant): string
    {
        return defined($constant) ? trim((string) constant($constant)) : '';
    }
}

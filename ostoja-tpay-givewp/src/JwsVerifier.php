<?php

namespace OstojaTpayGiveWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Weryfikacja podpisu JWS powiadomień Tpay (nagłówek X-JWS-Signature, payload odłączony).
 *
 * 1. Nagłówek JWS: alg = RS256, x5u wskazuje DOKŁADNIE certyfikat Tpay dla bieżącego środowiska
 *    (adres jest przypięty – nie pobieramy niczego z adresu podanego przez nadawcę).
 * 2. Certyfikat podpisujący musi być podpisany przez root CA Tpay i być ważny.
 * 3. Podpis RS256 nad "<nagłówek>.<base64url(body)>".
 */
final class JwsVerifier
{
    const CERT_TTL = 12 * HOUR_IN_SECONDS;
    const REFRESH_COOLDOWN = 5 * MINUTE_IN_SECONDS;

    public static function verify(string $jws, string $body, string $certBase): bool
    {
        if ($jws === '' || $body === '' || !function_exists('openssl_verify') || !function_exists('openssl_x509_verify')) {
            return false;
        }

        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return false;
        }
        list($encodedHeader, $encodedPayload, $encodedSignature) = $parts;

        $header = json_decode(self::base64UrlDecode($encodedHeader), true);
        $expectedX5u = $certBase . '/x509/notifications-jws.pem';
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256' || ($header['x5u'] ?? '') !== $expectedX5u) {
            return false;
        }

        $bodyEncoded = self::base64UrlEncode($body);
        // Payload odłączony (pusty). Gdyby jednak był dołączony, musi odpowiadać treści żądania.
        if ($encodedPayload !== '' && !hash_equals($bodyEncoded, $encodedPayload)) {
            return false;
        }

        $signature = self::base64UrlDecode($encodedSignature);
        if ($signature === '') {
            return false;
        }
        $signingInput = $encodedHeader . '.' . $bodyEncoded;

        if (self::verifyWithCerts($signingInput, $signature, $certBase, false)) {
            return true;
        }

        // Certyfikat mógł zostać zrotowany – jednorazowo odśwież cache (z ograniczeniem częstotliwości).
        $cooldownKey = 'ostoja_tpay_jws_refresh_' . md5($certBase);
        if (get_transient($cooldownKey)) {
            return false;
        }
        set_transient($cooldownKey, 1, self::REFRESH_COOLDOWN);

        return self::verifyWithCerts($signingInput, $signature, $certBase, true);
    }

    private static function verifyWithCerts(string $signingInput, string $signature, string $certBase, bool $refresh): bool
    {
        $cert = self::fetchPem($certBase . '/x509/notifications-jws.pem', $refresh);
        $root = self::fetchPem($certBase . '/x509/tpay-jws-root.pem', $refresh);
        if ($cert === '' || $root === '') {
            return false;
        }

        if (openssl_x509_verify($cert, $root) !== 1) {
            return false;
        }

        $info = openssl_x509_parse($cert);
        $now = time();
        if (!is_array($info) || ($info['validFrom_time_t'] ?? PHP_INT_MAX) > $now || ($info['validTo_time_t'] ?? 0) < $now) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($cert);
        if (!$publicKey) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function fetchPem(string $url, bool $refresh): string
    {
        $key = 'ostoja_tpay_pem_' . md5($url);
        if (!$refresh) {
            $cached = get_transient($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = wp_safe_remote_get($url, ['timeout' => 10, 'redirection' => 0]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }
        $pem = (string) wp_remote_retrieve_body($response);
        if (strpos($pem, '-----BEGIN CERTIFICATE-----') === false || strlen($pem) > 20000) {
            return '';
        }
        set_transient($key, $pem, self::CERT_TTL);

        return $pem;
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}

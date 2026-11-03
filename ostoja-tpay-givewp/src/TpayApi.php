<?php

namespace OstojaTpayGiveWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimalny klient Tpay Open API: OAuth, utworzenie transakcji, pobranie transakcji.
 */
final class TpayApi
{
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;

    public function __construct(string $baseUrl, string $clientId, string $clientSecret)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    public static function forEnvironment(bool $sandbox): self
    {
        if (!Config::isConfigured($sandbox)) {
            throw new TpayApiException(sprintf('Brak kluczy Tpay dla środowiska: %s.', Config::label($sandbox)));
        }

        return new self(Config::apiBase($sandbox), Config::clientId($sandbox), Config::clientSecret($sandbox));
    }

    /**
     * POST /transactions
     *
     * @return array{transactionId:string,title:string,transactionPaymentUrl:string}
     */
    public function createTransaction(array $body): array
    {
        $data = $this->request('POST', '/transactions', $body);

        $transactionId = isset($data['transactionId']) ? (string) $data['transactionId'] : '';
        $paymentUrl = isset($data['transactionPaymentUrl']) ? (string) $data['transactionPaymentUrl'] : '';

        if ($transactionId === '' || !self::isTrustedPaymentUrl($paymentUrl)) {
            throw new TpayApiException('Nieprawidłowa odpowiedź Tpay przy tworzeniu transakcji.');
        }

        return [
            'transactionId' => $transactionId,
            'title' => isset($data['title']) ? (string) $data['title'] : '',
            'transactionPaymentUrl' => $paymentUrl,
        ];
    }

    /**
     * GET /transactions/{transactionId}
     */
    public function getTransaction(string $transactionId): array
    {
        if ($transactionId === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $transactionId)) {
            throw new TpayApiException('Nieprawidłowy identyfikator transakcji.');
        }

        return $this->request('GET', '/transactions/' . rawurlencode($transactionId));
    }

    /**
     * Sprawdza, czy klucze są poprawne (pobiera świeży token).
     */
    public function testConnection(): void
    {
        $this->token(true);
    }

    /**
     * Adres płatności musi być HTTPS i należeć do domeny tpay.com – chroni przed
     * przekierowaniem darczyńcy w obce miejsce przy zmanipulowanej odpowiedzi.
     */
    public static function isTrustedPaymentUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        $host = strtolower($parts['host']);

        return $host === 'tpay.com' || substr($host, -strlen('.tpay.com')) === '.tpay.com';
    }

    private function request(string $method, string $path, ?array $json = null, bool $retried = false): array
    {
        $args = [
            'method' => $method,
            'timeout' => 20,
            'redirection' => 0,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token(),
                'Accept' => 'application/json',
            ],
        ];
        if ($json !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($json);
        }

        $response = wp_remote_request($this->baseUrl . $path, $args);
        if (is_wp_error($response)) {
            throw new TpayApiException('Błąd połączenia z Tpay: ' . $response->get_error_code());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // Token mógł wygasnąć wcześniej niż zakładaliśmy – jedna ponowna próba ze świeżym.
        if ($code === 401 && !$retried) {
            $this->forgetToken();
            return $this->request($method, $path, $json, true);
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($data)) {
            throw new TpayApiException(
                sprintf('Tpay API %s %s zwróciło HTTP %d%s', $method, strtok($path, '?'), $code, self::errorSummary($data)),
                $code
            );
        }

        return $data;
    }

    private function token(bool $forceRefresh = false): string
    {
        $key = $this->tokenCacheKey();
        if (!$forceRefresh) {
            $cached = get_transient($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = wp_remote_post($this->baseUrl . '/oauth/auth', [
            'timeout' => 15,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json'],
            'body' => [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ],
        ]);

        if (is_wp_error($response)) {
            throw new TpayApiException('Błąd połączenia z Tpay (autoryzacja): ' . $response->get_error_code());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($data) || empty($data['access_token'])) {
            throw new TpayApiException(sprintf('Autoryzacja w Tpay nie powiodła się (HTTP %d).', $code), $code);
        }

        $token = (string) $data['access_token'];
        $ttl = isset($data['expires_in']) ? (int) $data['expires_in'] - 120 : 3500;
        set_transient($key, $token, max(60, min($ttl, 7000)));

        return $token;
    }

    private function forgetToken(): void
    {
        delete_transient($this->tokenCacheKey());
    }

    /**
     * Klucz cache zależy od środowiska i kluczy – zmiana kluczy automatycznie unieważnia token.
     */
    private function tokenCacheKey(): string
    {
        return 'ostoja_tpay_tok_' . substr(hash('sha256', $this->baseUrl . '|' . $this->clientId . '|' . $this->clientSecret), 0, 24);
    }

    private static function errorSummary($data): string
    {
        if (!is_array($data) || empty($data['errors']) || !is_array($data['errors'])) {
            return '';
        }
        $codes = [];
        foreach (array_slice($data['errors'], 0, 3) as $error) {
            if (is_array($error)) {
                $codes[] = sanitize_text_field(($error['errorCode'] ?? '') . ' ' . ($error['fieldName'] ?? ''));
            }
        }

        return $codes ? ' (' . implode('; ', $codes) . ')' : '';
    }
}

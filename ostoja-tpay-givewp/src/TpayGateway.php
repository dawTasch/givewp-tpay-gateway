<?php

namespace OstojaTpayGiveWP;

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\Exceptions\PaymentGatewayException;
use Give\Framework\PaymentGateways\Log\PaymentGatewayLog;
use Give\Framework\PaymentGateways\PaymentGateway;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bramka Tpay dla GiveWP (płatność poza stroną – przekierowanie).
 *
 * Przepływ:
 *  1. GiveWP tworzy darowiznę „pending” i wywołuje createPayment().
 *  2. Tworzymy transakcję w Tpay i przekierowujemy darczyńcę na transactionPaymentUrl.
 *  3. Tpay wysyła powiadomienie na handleNotification() – weryfikujemy JWS (+ md5sum),
 *     pobieramy transakcję z API Tpay i dopiero wtedy oznaczamy darowiznę jako opłaconą.
 *  4. Zmiana statusu na „complete” uruchamia standardowe maile GiveWP (potwierdzenie dla darczyńcy).
 *
 * Powrót darczyńcy na stronę NIE zmienia statusu – to robi wyłącznie powiadomienie.
 */
class TpayGateway extends PaymentGateway
{
    const GATEWAY_ID = 'ostoja-tpay';
    const CRC_PREFIX = 'givewp-';

    /**
     * Publiczna trasa (bez podpisu GiveWP – Tpay nie zna naszego podpisu). Bezpieczeństwo zapewnia JWS.
     *
     * @var string[]
     */
    public $routeMethods = ['handleNotification'];

    public static function id(): string
    {
        return self::GATEWAY_ID;
    }

    public function getId(): string
    {
        return self::id();
    }

    public function getName(): string
    {
        return __('Tpay', 'ostoja-tpay-givewp');
    }

    public function getPaymentMethodLabel(): string
    {
        return __('BLIK, przelew lub karta (Tpay)', 'ostoja-tpay-givewp');
    }

    /**
     * Formularze „Option-Based Form Editor” (GiveWP v2).
     */
    public function getLegacyFormFieldMarkup(int $formId, array $args): string
    {
        return '<div class="ostoja-tpay-info"><p>' . esc_html($this->infoMessage()) . '</p></div>';
    }

    /**
     * Formularze „Visual Form Builder” (GiveWP v3).
     */
    public function enqueueScript(int $formId)
    {
        wp_enqueue_script(
            self::id(),
            OSTOJA_TPAY_GIVEWP_URL . 'assets/js/tpay-gateway.js',
            ['react', 'wp-element'],
            OSTOJA_TPAY_GIVEWP_VERSION,
            true
        );
    }

    /**
     * Dane przekazywane do przeglądarki – WYŁĄCZNIE tekst informacyjny, żadnych kluczy.
     */
    public function formSettings(int $formId): array
    {
        return ['message' => $this->infoMessage()];
    }

    /**
     * @throws PaymentGatewayException
     */
    public function createPayment(Donation $donation, $gatewayData)
    {
        try {
            $currency = strtoupper($donation->amount->getCurrency()->getCode());
            if ($currency !== 'PLN') {
                throw new TpayApiException('Tpay obsługuje w tej wtyczce tylko PLN, otrzymano ' . $currency . '.');
            }

            $successUrl = self::returnUrl($gatewayData['successUrl'] ?? '', give_get_success_page_uri());
            $errorUrl = self::returnUrl($gatewayData['cancelUrl'] ?? '', give_get_failed_transaction_uri());

            $payerName = trim($donation->firstName . ' ' . $donation->lastName);

            $body = [
                'amount' => (float) $donation->amount->formatToDecimal(),
                'description' => self::truncate(sprintf(__('Darowizna: %s', 'ostoja-tpay-givewp'), $donation->formTitle), 128),
                'hiddenDescription' => self::CRC_PREFIX . $donation->id,
                'lang' => 'pl',
                'payer' => [
                    'email' => $donation->email,
                    'name' => $payerName !== '' ? self::truncate($payerName, 255) : __('Darczyńca', 'ostoja-tpay-givewp'),
                ],
                'callbacks' => [
                    'payerUrls' => [
                        'success' => $successUrl,
                        'error' => $errorUrl,
                    ],
                    'notification' => [
                        'url' => $this->generateGatewayRouteUrl('handleNotification'),
                    ],
                ],
            ];

            // GiveWP zapisuje w darowiźnie tryb (test/live) zgodny z „Trybem testowym” w chwili jej utworzenia.
            $sandbox = self::isTestDonation($donation);
            $transaction = TpayApi::forEnvironment($sandbox)->createTransaction($body);

            $donation->gatewayTransactionId = $transaction['transactionId'];
            $donation->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    /* translators: 1: Tpay transaction title, 2: environment */
                    __('Utworzono transakcję Tpay %1$s (%2$s). Oczekiwanie na płatność.', 'ostoja-tpay-givewp'),
                    $transaction['title'] !== '' ? $transaction['title'] : $transaction['transactionId'],
                    Config::label($sandbox)
                ),
            ]);

            return new RedirectOffsite($transaction['transactionPaymentUrl']);
        } catch (\Exception $e) {
            $donation->status = DonationStatus::FAILED();
            $donation->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(__('Nie udało się utworzyć płatności Tpay: %s', 'ostoja-tpay-givewp'), $e->getMessage()),
            ]);
            PaymentGatewayLog::error('Tpay: createPayment failed', [
                'Donation ID' => $donation->id,
                'Error' => $e->getMessage(),
            ]);

            // Komunikat dla darczyńcy bez szczegółów technicznych.
            throw new PaymentGatewayException(
                __('Nie udało się rozpocząć płatności. Spróbuj ponownie za chwilę.', 'ostoja-tpay-givewp')
            );
        }
    }

    /**
     * Odbiór powiadomienia Tpay (POST application/x-www-form-urlencoded + nagłówek X-JWS-Signature).
     * Tpay oczekuje odpowiedzi „TRUE” (HTTP 200); każda inna powoduje ponowienie.
     */
    protected function handleNotification(array $queryParams)
    {
        list($status, $body) = $this->processNotification();
        self::respond($status, $body);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function processNotification(): array
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return [405, 'FALSE'];
        }

        $raw = (string) file_get_contents('php://input');
        if ($raw === '' || strlen($raw) > 20000) {
            return [400, 'FALSE'];
        }

        $jws = isset($_SERVER['HTTP_X_JWS_SIGNATURE']) ? trim((string) wp_unslash($_SERVER['HTTP_X_JWS_SIGNATURE'])) : '';
        if ($jws === '') {
            return [401, 'FALSE'];
        }

        parse_str($raw, $data);
        $field = static function (string $key) use ($data): string {
            return isset($data[$key]) && is_scalar($data[$key]) ? trim((string) $data[$key]) : '';
        };

        $merchantId = $field('id');
        $trId = $field('tr_id');
        $trAmount = $field('tr_amount');
        $trCrc = $field('tr_crc');
        $trStatus = strtoupper($field('tr_status'));

        // Najpierw ustalamy darowiznę (tylko odczyt), bo od niej zależy środowisko,
        // w którym weryfikujemy podpis i transakcję. Nic nie zmieniamy przed weryfikacją JWS.
        if (!preg_match('/^' . preg_quote(self::CRC_PREFIX, '/') . '(\d{1,20})$/', $trCrc, $m)) {
            return [400, 'FALSE'];
        }

        /** @var Donation|null $donation */
        $donation = Donation::find((int) $m[1]);
        if (!$donation || $donation->gatewayId !== self::id() || (string) $donation->gatewayTransactionId === '') {
            return [400, 'FALSE'];
        }

        // Środowisko darowizny (zapisane przez GiveWP przy jej tworzeniu), a nie bieżące ustawienie –
        // powiadomienie z Sandboxa nigdy nie oznaczy prawdziwej darowizny jako opłaconej i odwrotnie.
        $sandbox = self::isTestDonation($donation);

        if (!JwsVerifier::verify($jws, $raw, Config::certBase($sandbox))) {
            PaymentGatewayLog::error('Tpay: odrzucono powiadomienie z nieprawidłowym podpisem JWS', [
                'Donation ID' => $donation->id,
                'Environment' => Config::label($sandbox),
            ]);
            return [401, 'FALSE'];
        }

        $securityCode = Config::securityCode($sandbox);
        if ($securityCode !== '') {
            $expected = md5($merchantId . $trId . $trAmount . $trCrc . $securityCode);
            if (!hash_equals($expected, strtolower($field('md5sum')))) {
                PaymentGatewayLog::error('Tpay: niezgodna suma md5sum powiadomienia', ['tr_id' => $trId]);
                return [401, 'FALSE'];
            }
        }

        // Blokada przed równoległym przetwarzaniem tej samej darowizny (np. podwójne powiadomienie).
        $lock = 'ostoja_tpay_lock_' . $donation->id;
        if (!add_option($lock, time(), '', 'no')) {
            if ((int) get_option($lock) > time() - 60) {
                return [503, 'FALSE']; // Tpay ponowi później.
            }
            update_option($lock, time(), false);
        }

        try {
            return $this->applyNotification($donation, $trStatus, $trId, $trCrc, $field('tr_paid'));
        } finally {
            delete_option($lock);
        }
    }

    /**
     * @return array{0:int,1:string}
     */
    private function applyNotification(Donation $donation, string $trStatus, string $trId, string $trCrc, string $trPaid = ''): array
    {
        // Źródłem prawdy jest API Tpay (w środowisku darowizny), a nie treść powiadomienia.
        try {
            $tx = TpayApi::forEnvironment(self::isTestDonation($donation))->getTransaction((string) $donation->gatewayTransactionId);
        } catch (TpayApiException $e) {
            PaymentGatewayLog::error('Tpay: nie udało się zweryfikować transakcji w API', [
                'Donation ID' => $donation->id,
                'Error' => $e->getMessage(),
            ]);
            return [503, 'FALSE'];
        }

        $expectedCents = self::toCents($donation->amount->formatToDecimal());
        $txCurrency = strtoupper((string) ($tx['currency'] ?? 'PLN'));
        $txHidden = (string) ($tx['hiddenDescription'] ?? $trCrc);
        $txId = (string) ($tx['transactionId'] ?? $donation->gatewayTransactionId);
        $txAmountCents = isset($tx['amount']) ? self::toCents((string) $tx['amount']) : $expectedCents;

        if ($txId !== (string) $donation->gatewayTransactionId || $txHidden !== $trCrc || $txCurrency !== 'PLN' || $txAmountCents !== $expectedCents) {
            PaymentGatewayLog::error('Tpay: transakcja w API nie zgadza się z darowizną', [
                'Donation ID' => $donation->id,
                'tr_id' => $trId,
            ]);
            DonationNote::create([
                'donationId' => $donation->id,
                'content' => __('Tpay: dane transakcji nie zgadzają się z darowizną (kwota/waluta/ID). Status NIE został zmieniony – sprawdź w Panelu Tpay.', 'ostoja-tpay-givewp'),
            ]);
            return [400, 'FALSE'];
        }

        $apiStatus = strtolower((string) ($tx['status'] ?? ''));

        // Zapłacona kwota: z API (payments.amountPaid), a gdy API jej nie podaje (Sandbox zwraca
        // tylko payments.attempts) – z pola tr_paid podpisanego powiadomienia (zweryfikowane JWS).
        if (isset($tx['payments']['amountPaid']) && is_numeric($tx['payments']['amountPaid'])) {
            $paidCents = self::toCents((string) $tx['payments']['amountPaid']);
        } elseif ($trPaid !== '' && is_numeric(str_replace(',', '.', $trPaid))) {
            $paidCents = self::toCents($trPaid);
        } else {
            $paidCents = 0;
        }
        $current = $donation->status->getValue();

        if ($trStatus === 'TRUE') {
            if ($current === DonationStatus::COMPLETE || $current === DonationStatus::REFUNDED) {
                return [200, 'TRUE']; // Już przetworzone – idempotencja.
            }

            if (!in_array($apiStatus, ['correct', 'paid'], true) || $paidCents < $expectedCents) {
                // API jeszcze nie potwierdza – odpowiadamy błędem, Tpay ponowi powiadomienie.
                return [503, 'FALSE'];
            }

            $donation->status = DonationStatus::COMPLETE();
            $donation->save();

            DonationNote::create([
                'donationId' => $donation->id,
                'content' => sprintf(
                    __('Płatność potwierdzona przez Tpay (%1$s), zapłacono %2$s PLN.', 'ostoja-tpay-givewp'),
                    $trId,
                    number_format($paidCents / 100, 2, ',', ' ')
                ),
            ]);

            return [200, 'TRUE'];
        }

        if ($trStatus === 'CHARGEBACK') {
            if ($current === DonationStatus::COMPLETE) {
                $donation->status = DonationStatus::REFUNDED();
                $donation->save();
                DonationNote::create([
                    'donationId' => $donation->id,
                    'content' => sprintf(__('Tpay zgłosił zwrot środków (%s).', 'ostoja-tpay-givewp'), $trId),
                ]);
            }

            return [200, 'TRUE'];
        }

        // Nieznany status – potwierdzamy odbiór, ale nic nie zmieniamy.
        PaymentGatewayLog::error('Tpay: nieobsługiwany tr_status', ['Donation ID' => $donation->id, 'tr_status' => $trStatus]);

        return [200, 'TRUE'];
    }

    /**
     * Adres powrotu darczyńcy dla Tpay (payerUrls).
     *
     * GiveWP (Visual Form Builder) przekazuje successUrl/cancelUrl zakodowane przez rawurlencode()
     * (zob. Give\DonationForms\Listeners\AddRedirectUrlsToGatewayData). Tpay wymaga zwykłego adresu
     * http(s) – zakodowany odrzuca i odsyła darczyńcę na tpay.com. Dlatego dekodujemy i walidujemy;
     * adres musi prowadzić do tej samej witryny, w razie problemu używamy wartości zapasowej.
     *
     * @param mixed $value
     */
    public static function returnUrl($value, string $fallback): string
    {
        $url = is_string($value) ? trim($value) : '';
        // Maksymalnie dwa poziomy kodowania (np. %253A) – bez pętli bez końca.
        for ($i = 0; $i < 2 && $url !== '' && !preg_match('#^https?://#i', $url); $i++) {
            $url = rawurldecode($url);
        }

        $siteHost = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $isValid = static function (string $candidate) use ($siteHost): bool {
            if ($candidate === '' || preg_match('/\s/', $candidate)) {
                return false;
            }
            $parts = wp_parse_url($candidate);
            if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
                return false;
            }

            return strtolower((string) ($parts['host'] ?? '')) === $siteHost;
        };

        if ($isValid($url)) {
            return $url;
        }

        return $isValid($fallback) ? $fallback : home_url('/');
    }

    /**
     * Darowizna testowa (GiveWP „Tryb testowy”) → Sandbox Tpay, w przeciwnym razie produkcja.
     */
    private static function isTestDonation(Donation $donation): bool
    {
        return $donation->mode !== null && $donation->mode->getValue() === 'test';
    }

    private function infoMessage(): string
    {
        return __('Po kliknięciu przycisku przejdziesz do bezpiecznej płatności Tpay (BLIK, przelew, karta). Dane płatnicze nie trafiają na tę stronę.', 'ostoja-tpay-givewp');
    }

    private static function respond(int $status, string $body)
    {
        if (!headers_sent()) {
            status_header($status);
            header('Content-Type: text/plain; charset=utf-8');
            nocache_headers();
        }
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stała wartość TRUE/FALSE
        exit;
    }

    private static function toCents(string $amount): int
    {
        return (int) round(((float) str_replace(',', '.', $amount)) * 100);
    }

    private static function truncate(string $text, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($text, 0, $length) : substr($text, 0, $length);
    }
}

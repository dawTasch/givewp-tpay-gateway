<?php

namespace OstojaTpayGiveWP;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Błąd komunikacji z API Tpay. Komunikat nigdy nie zawiera sekretów ani tokenów.
 */
class TpayApiException extends \RuntimeException
{
    /** @var int */
    private $httpStatus;

    public function __construct(string $message, int $httpStatus = 0)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Błąd przejściowy (sieć, 5xx, 429) – warto ponowić. */
    public function isTemporary(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}

<?php

namespace GlobalXtreme\ASA;

use RuntimeException;

/**
 * Dilempar Webhook::push() bila panggilan gagal atau ASA menjawab status di luar 2xx.
 * Kode exception berisi status HTTP jawaban ASA (0 bila tidak ada jawaban).
 */
class WebhookException extends RuntimeException
{
    /** @var WebhookResult */
    private $result;

    public function __construct(WebhookResult $result)
    {
        parent::__construct((string) $result->error, $result->statusCode);

        $this->result = $result;
    }

    /**
     * Jawaban ASA (bila ada) beserta pesan error-nya
     */
    public function result(): WebhookResult
    {
        return $this->result;
    }
}

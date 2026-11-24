<?php

namespace GlobalXtreme\ASA;

/**
 * Jawaban ASA atas satu panggilan webhook
 */
class WebhookResult
{
    /** @var int Status HTTP jawaban; 0 bila tidak ada jawaban (mis. ASA tidak bisa dihubungi) */
    public $statusCode = 0;

    /** @var array<string, string> Header jawaban, nama huruf kecil */
    public $headers = [];

    /** @var string|null Terisi bila node Webhook memakai Respond "Immediately" */
    public $executionUuid;

    /**
     * @var string Isi jawaban apa adanya. Pada Respond "When last node finishes" dan
     *             "Using Respond to Webhook node" isinya ditentukan workflow
     */
    public $body = '';

    /** @var string|null Pesan error; null bila panggilan berhasil */
    public $error;

    public function successful(): bool
    {
        return $this->error === null;
    }

    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * Mengurai body sebagai JSON. Mengembalikan null bila body bukan JSON yang sah
     *
     * @return mixed
     */
    public function json(bool $associative = true)
    {
        return json_decode($this->body, $associative);
    }
}

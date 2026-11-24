<?php

namespace GlobalXtreme\ASA\Transport;

/**
 * Pengirim permintaan HTTP. Bawaan: CurlTransport. Diganti hanya untuk tes (Webhook::useTransport).
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @param string|null $body
     * @return array{status: int, headers: array<string, string>, body: string}
     *
     * @throws \RuntimeException bila tidak ada jawaban (tidak terhubung, waktu habis)
     */
    public function send(string $method, string $url, array $headers, $body, int $timeout): array;
}

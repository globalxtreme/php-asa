<?php

namespace GlobalXtreme\ASA\Transport;

use RuntimeException;

class CurlTransport implements Transport
{
    /** Batas isi jawaban yang dibaca, supaya jawaban yang sangat besar tidak memenuhi memori */
    const MAX_RESPONSE_SIZE = 10485760;

    public function send(string $method, string $url, array $headers, $body, int $timeout): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $responseBody = '';

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$responseBody) {
                $remaining = self::MAX_RESPONSE_SIZE - strlen($responseBody);
                if ($remaining > 0) {
                    $responseBody .= substr($chunk, 0, $remaining);
                }

                return strlen($chunk);
            },
        ]);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $ok = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($ok === false) {
            throw new RuntimeException($error !== '' ? $error : 'request failed');
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }
}

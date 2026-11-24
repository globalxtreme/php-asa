<?php

namespace GlobalXtreme\ASA;

use GlobalXtreme\ASA\Transport\CurlTransport;
use GlobalXtreme\ASA\Transport\Transport;
use Throwable;

/**
 * Memulai workflow ASA lewat node Webhook (trigger) dari service mana pun.
 *
 *     Webhook::path('/wo-created')
 *         ->data(['woNumber' => 'WO-2026-00123'])
 *         ->push(); // panggil SETELAH transaction commit
 *
 * Domain ASA dan token selalu dibaca dari environment (ASA_WEBHOOK_HOST, ASA_WEBHOOK_TOKEN; opsional
 * ASA_WEBHOOK_TIMEOUT dalam detik, default 10). Pemanggil hanya mengisi path dan, bila ada, data, query, dan
 * headers. Node Webhook di ASA harus memakai Authentication "GX Service" dan memilih service pemilik token.
 *
 * Satu panggilan memulai satu workflow (path + method unik di ASA). Untuk memulai beberapa workflow dari satu
 * kejadian, arahkan path ke workflow dispatcher yang memanggil workflow lain dengan node Execute Sub-workflow.
 *
 * Panggilan tidak diulang otomatis: setiap panggilan yang diterima membuat satu eksekusi baru.
 */
class Webhook
{
    /** Prefix URL webhook ASA; path node Webhook ditulis sesudahnya */
    const PREFIX = '/api/webhook/v1/asa-automations';

    /** Batas waktu bawaan panggilan, dalam detik */
    const DEFAULT_TIMEOUT = 10;

    /** @var Transport|null */
    private static $transport;

    /** @var string */
    private $path;

    /** @var string */
    private $method = 'POST';

    /** @var mixed */
    private $data;

    /** @var array */
    private $query = [];

    /** @var array<string, string> */
    private $headers = [];

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Path node Webhook, mis. "/wo-created"
     */
    public static function path(string $path): self
    {
        return new self($path);
    }

    /**
     * Method node Webhook. Default POST
     */
    public function method(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Dikirim sebagai body JSON; di workflow dibaca dengan $json.body
     *
     * @param mixed $data
     */
    public function data($data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Dikirim sebagai query string; di workflow dibaca dengan $json.query
     */
    public function query(array $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Header tambahan; di workflow dibaca dengan $json.headers (nama huruf kecil).
     * Header Authorization selalu diisi ASA Token dan tidak bisa diganti dari sini
     *
     * @param array<string, string> $headers
     */
    public function headers(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * Memanggil webhook dan melempar WebhookException bila gagal.
     * Pakai tryPush() bila kegagalan memulai workflow tidak boleh menggagalkan proses pemanggil.
     *
     * @throws WebhookException
     */
    public function push(): WebhookResult
    {
        $result = $this->tryPush();
        if ($result->failed()) {
            throw new WebhookException($result);
        }

        return $result;
    }

    /**
     * Memanggil webhook tanpa melempar exception. Bila panggilan gagal atau ASA menjawab status di luar 2xx,
     * $result->error berisi pesannya dan $result tetap berisi jawaban ASA (bila ada).
     */
    public function tryPush(): WebhookResult
    {
        $result = new WebhookResult();

        $path = trim($this->path, '/');
        if ($path === '') {
            return $this->fail($result, 'ASA webhook path is required');
        }

        $host = rtrim((string) self::config('host', 'ASA_WEBHOOK_HOST'), '/');
        if ($host === '') {
            return $this->fail($result, 'Please set ASA_WEBHOOK_HOST environment variable');
        }

        $token = (string) self::config('token', 'ASA_WEBHOOK_TOKEN');
        if ($token === '') {
            return $this->fail($result, 'Please set ASA_WEBHOOK_TOKEN environment variable');
        }

        $url = $host . self::PREFIX . '/' . $path;
        if (count($this->query) > 0) {
            $url .= '?' . http_build_query($this->query, '', '&', PHP_QUERY_RFC3986);
        }

        $method = strtoupper(trim($this->method));
        if ($method === '') {
            $method = 'POST';
        }

        $headers = [];
        $body = null;

        if ($this->data !== null) {
            $body = json_encode($this->data);
            if ($body === false) {
                return $this->fail($result, sprintf('encode ASA webhook %s payload: %s', $this->path, json_last_error_msg()));
            }

            $headers['Content-Type'] = 'application/json';
        }

        $headers['Accept'] = 'application/json';

        foreach ($this->headers as $name => $value) {
            if (strtolower((string) $name) !== 'authorization') {
                $headers[(string) $name] = (string) $value;
            }
        }

        // Selalu paling akhir, supaya headers dari pemanggil tidak menimpa token
        $headers['Authorization'] = 'Bearer ' . $token;

        try {
            $response = self::transport()->send($method, $url, $headers, $body, self::timeout());
        } catch (Throwable $e) {
            return $this->fail($result, sprintf('call ASA webhook %s: %s', $this->path, $e->getMessage()));
        }

        $result->statusCode = (int) $response['status'];
        $result->headers = $response['headers'];
        $result->body = (string) $response['body'];

        // Amplop standar GX: sukses berisi result.executionUuid, error berisi status.message
        $envelope = json_decode($result->body, true);
        if (!is_array($envelope)) {
            $envelope = [];
        }

        if ($result->statusCode < 200 || $result->statusCode > 299) {
            $status = isset($envelope['status']) && is_array($envelope['status']) ? $envelope['status'] : [];

            $message = isset($status['message']) && is_string($status['message']) ? $status['message'] : '';
            if (isset($status['internalMsg']) && is_string($status['internalMsg']) && $status['internalMsg'] !== '') {
                $message .= '. Internal-Msg: ' . $status['internalMsg'];
            }
            if ($message === '') {
                $message = 'request failed';
            }

            return $this->fail($result, sprintf('ASA webhook %s responded with status %d: %s', $this->path, $result->statusCode, $message));
        }

        if (isset($envelope['result']) && is_array($envelope['result']) && isset($envelope['result']['executionUuid']) && is_string($envelope['result']['executionUuid'])) {
            $result->executionUuid = $envelope['result']['executionUuid'];
        }

        return $result;
    }

    /**
     * Mengganti pengirim HTTP; hanya untuk tes. null mengembalikan pengirim bawaan (cURL).
     */
    public static function useTransport(?Transport $transport): void
    {
        self::$transport = $transport;
    }

    /** --- PRIVATE --- */

    private function fail(WebhookResult $result, string $message): WebhookResult
    {
        $result->error = $message;

        return $result;
    }

    private static function transport(): Transport
    {
        if (self::$transport === null) {
            self::$transport = new CurlTransport();
        }

        return self::$transport;
    }

    private static function timeout(): int
    {
        $seconds = (int) self::config('timeout', 'ASA_WEBHOOK_TIMEOUT');

        return $seconds > 0 ? $seconds : self::DEFAULT_TIMEOUT;
    }

    /**
     * Nilai konfigurasi: di Laravel dari config('asa.webhook.*') (tetap terbaca saat config di-cache), selain itu
     * langsung dari environment.
     *
     * @return mixed
     */
    private static function config(string $key, string $env)
    {
        if (function_exists('app') && function_exists('config')) {
            try {
                if (app()->bound('config')) {
                    $value = config('asa.webhook.' . $key);
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                }
            } catch (Throwable $e) {
                // Bukan aplikasi Laravel yang sudah siap: baca dari environment
            }
        }

        foreach ([isset($_ENV[$env]) ? $_ENV[$env] : null, isset($_SERVER[$env]) ? $_SERVER[$env] : null, getenv($env)] as $value) {
            if ($value !== null && $value !== false && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

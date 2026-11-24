# globalxtreme/php-asa

Package PHP untuk ASA Automation GlobalXtreme. Versi PHP dari package `asa` di go-core.

Saat ini berisi **Webhook**: memulai workflow ASA lewat node Webhook (trigger) dari service mana pun.

- PHP 7.4 ke atas (termasuk PHP 8)
- Laravel 7 ke atas, atau PHP tanpa framework
- Tanpa dependensi selain ekstensi `curl` dan `json`

## Instalasi

```bash
composer require globalxtreme/php-asa
```

Di Laravel, service provider terdaftar otomatis.

## Environment

| Variabel | Wajib | Keterangan |
| --- | --- | --- |
| `ASA_WEBHOOK_HOST` | ya | Domain ASA beserta skemanya, mis. `https://asa.globalxtreme-solutions.net` |
| `ASA_WEBHOOK_TOKEN` | ya | ASA Token milik service ini (menu **Services** di ASA) |
| `ASA_WEBHOOK_TIMEOUT` | tidak | Batas waktu panggilan dalam detik, default 10 |

Domain dan token **selalu** dibaca dari environment, tidak ditulis di kode.

Di Laravel, nilainya dibaca lewat `config/asa.php` milik package, sehingga tetap terbaca saat config di-cache
(`php artisan config:cache`). File config hanya perlu di-publish bila ingin diubah:

```bash
php artisan vendor:publish --tag=asa-config
```

## Pemakaian

Yang ditulis pemanggil hanya **path**, dan bila ada **data**, **query**, dan **headers**.

```php
use GlobalXtreme\ASA\Webhook;

Webhook::path('/wo-created')
    ->data(['woNumber' => 'WO-2026-00123'])
    ->push();
```

Lengkap:

```php
$result = Webhook::path('/wo-created')
    ->method('POST')                              // default POST
    ->data(['woNumber' => 'WO-2026-00123'])       // body JSON, di workflow: $json.body
    ->query(['source' => 'crm'])                  // query string, di workflow: $json.query
    ->headers(['X-Request-Id' => 'req-1'])        // header tambahan, di workflow: $json.headers
    ->push();

$result->statusCode;     // status HTTP jawaban ASA
$result->executionUuid;  // terisi bila node Webhook memakai Respond "Immediately"
$result->body;           // isi jawaban apa adanya
$result->json();         // isi jawaban sebagai array
```

### push() dan tryPush()

- `push()` melempar `GlobalXtreme\ASA\WebhookException` bila panggilan gagal atau ASA menjawab status di luar 2xx.
- `tryPush()` tidak pernah melempar exception. Pakai bila kegagalan memulai workflow tidak boleh menggagalkan
  proses pemanggil.

```php
$result = Webhook::path('/wo-created')->data($payload)->tryPush();

if ($result->failed()) {
    Log::warning($result->error);
}
```

```php
use GlobalXtreme\ASA\WebhookException;

try {
    Webhook::path('/wo-created')->data($payload)->push();
} catch (WebhookException $e) {
    $e->getMessage();          // pesan error
    $e->getCode();             // status HTTP jawaban ASA, 0 bila tidak ada jawaban
    $e->result()->body;        // isi jawaban ASA
}
```

## Aturan pakai

- Panggil **setelah** transaction database commit, supaya workflow tidak berjalan untuk data yang batal disimpan.
- Node Webhook di ASA harus memakai Authentication **GX Service** dan memilih service pemilik token.
- Method panggilan harus sama dengan method node Webhook (default package: `POST`).
- Header `Authorization` selalu diisi ASA Token dan tidak bisa diganti lewat `headers()`.
- Satu panggilan memulai satu workflow (path + method unik di ASA). Untuk memulai beberapa workflow dari satu
  kejadian, arahkan path ke workflow dispatcher yang memanggil workflow lain dengan node Execute Sub-workflow.
- Panggilan tidak diulang otomatis: setiap panggilan yang diterima membuat satu eksekusi baru.

## Tes

```bash
composer install
```

```bash
composer test
```

<?php

namespace GlobalXtreme\ASA\Tests;

use GlobalXtreme\ASA\Transport\Transport;
use GlobalXtreme\ASA\Webhook;
use GlobalXtreme\ASA\WebhookException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FakeTransport implements Transport
{
    /** @var array */
    public $sent = [];

    /** @var array|RuntimeException */
    public $answer = ['status' => 200, 'headers' => [], 'body' => ''];

    public function send(string $method, string $url, array $headers, $body, int $timeout): array
    {
        $this->sent[] = compact('method', 'url', 'headers', 'body', 'timeout');

        if ($this->answer instanceof RuntimeException) {
            throw $this->answer;
        }

        return $this->answer;
    }
}

class WebhookTest extends TestCase
{
    const TOKEN = 'asa_test_token';

    /** @var FakeTransport */
    private $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        Webhook::useTransport($this->transport);

        $this->env('ASA_WEBHOOK_HOST', 'https://asa.test/');
        $this->env('ASA_WEBHOOK_TOKEN', self::TOKEN);
        $this->env('ASA_WEBHOOK_TIMEOUT', null);
    }

    protected function tearDown(): void
    {
        Webhook::useTransport(null);

        $this->env('ASA_WEBHOOK_HOST', null);
        $this->env('ASA_WEBHOOK_TOKEN', null);
        $this->env('ASA_WEBHOOK_TIMEOUT', null);
    }

    private function env(string $name, ?string $value): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($value === null ? $name : $name . '=' . $value);
    }

    public function testPathSlashesAreNormalized(): void
    {
        foreach (['wo-created', '/wo-created', 'wo-created/', '//wo-created//', ' /wo-created '] as $path) {
            Webhook::path($path)->tryPush();

            $sent = end($this->transport->sent);
            $this->assertSame('https://asa.test/api/webhook/v1/asa-automations/wo-created', $sent['url'], $path);
        }

        Webhook::path('/orders/created')->tryPush();

        $sent = end($this->transport->sent);
        $this->assertSame('https://asa.test/api/webhook/v1/asa-automations/orders/created', $sent['url']);
    }

    public function testPushSendsRequestAndReadsExecution(): void
    {
        $this->transport->answer = [
            'status' => 200,
            'headers' => ['content-type' => 'application/json'],
            'body' => '{"status":{"code":200,"message":"Success"},"result":{"executionUuid":"exec-1"}}',
        ];

        $result = Webhook::path('wo-created')
            ->data(['woNumber' => 'WO-1'])
            ->query(['source' => 'crm', 'note' => 'a b'])
            ->headers(['X-Request-Id' => 'req-1'])
            ->push();

        $this->assertTrue($result->successful());
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('exec-1', $result->executionUuid);

        $sent = $this->transport->sent[0];
        $this->assertSame('POST', $sent['method']);
        $this->assertSame('https://asa.test/api/webhook/v1/asa-automations/wo-created?source=crm&note=a%20b', $sent['url']);
        $this->assertSame('Bearer ' . self::TOKEN, $sent['headers']['Authorization']);
        $this->assertSame('req-1', $sent['headers']['X-Request-Id']);
        $this->assertSame('application/json', $sent['headers']['Content-Type']);
        $this->assertSame(['woNumber' => 'WO-1'], json_decode($sent['body'], true));
        $this->assertSame(Webhook::DEFAULT_TIMEOUT, $sent['timeout']);
    }

    public function testExecutionMetadataIsSentAsHeaders(): void
    {
        Webhook::path('wo-created')
            ->headers(['x-asa-created-by' => 'other'])
            ->description("WO-1 dibuat\r\noleh CRM")
            ->createdBy(15)
            ->createdByName('Yuswa')
            ->tryPush();

        $headers = $this->transport->sent[0]['headers'];
        $this->assertSame('WO-1 dibuat oleh CRM', $headers['X-ASA-Description']);
        $this->assertSame('15', $headers['X-ASA-Created-By']);
        $this->assertSame('Yuswa', $headers['X-ASA-Created-By-Name']);
        $this->assertArrayNotHasKey('x-asa-created-by', $headers);
    }

    public function testEmptyExecutionMetadataIsNotSent(): void
    {
        Webhook::path('ping')->tryPush();

        $headers = $this->transport->sent[0]['headers'];
        $this->assertArrayNotHasKey('X-ASA-Description', $headers);
        $this->assertArrayNotHasKey('X-ASA-Created-By', $headers);
        $this->assertArrayNotHasKey('X-ASA-Created-By-Name', $headers);
    }

    public function testOnlyPathIsNeeded(): void
    {
        $result = Webhook::path('ping')->method('get')->tryPush();

        $this->assertTrue($result->successful());

        $sent = $this->transport->sent[0];
        $this->assertSame('GET', $sent['method']);
        $this->assertSame('https://asa.test/api/webhook/v1/asa-automations/ping', $sent['url']);
        $this->assertNull($sent['body']);
        $this->assertArrayNotHasKey('Content-Type', $sent['headers']);
    }

    public function testTokenCannotBeOverriddenByHeaders(): void
    {
        Webhook::path('ping')->headers(['authorization' => 'Bearer other', 'Authorization' => 'Bearer other'])->tryPush();

        $headers = $this->transport->sent[0]['headers'];
        $this->assertSame('Bearer ' . self::TOKEN, $headers['Authorization']);
        $this->assertArrayNotHasKey('authorization', $headers);
    }

    public function testFailureStatusKeepsAnswerAndMessage(): void
    {
        $this->transport->answer = [
            'status' => 401,
            'headers' => [],
            'body' => '{"status":{"code":401,"message":"Webhook authentication failed","internalMsg":"unknown service token"}}',
        ];

        $result = Webhook::path('wo-created')->tryPush();

        $this->assertTrue($result->failed());
        $this->assertSame(401, $result->statusCode);
        $this->assertStringContainsString('401', $result->error);
        $this->assertStringContainsString('Webhook authentication failed', $result->error);
        $this->assertStringContainsString('unknown service token', $result->error);
        $this->assertNotSame('', $result->body);
    }

    public function testPushThrowsOnFailure(): void
    {
        $this->transport->answer = ['status' => 500, 'headers' => [], 'body' => '<html>Bad Gateway</html>'];

        try {
            Webhook::path('wo-created')->push();
            $this->fail('WebhookException expected');
        } catch (WebhookException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertSame(500, $e->result()->statusCode);
            $this->assertSame('<html>Bad Gateway</html>', $e->result()->body);
            $this->assertStringContainsString('request failed', $e->getMessage());
        }
    }

    public function testRawWorkflowResponseIsKept(): void
    {
        $this->transport->answer = ['status' => 200, 'headers' => [], 'body' => '{"approved":true}'];

        $result = Webhook::path('approve')->push();

        $this->assertNull($result->executionUuid);
        $this->assertSame(['approved' => true], $result->json());
    }

    public function testEnvironmentAndPathAreRequired(): void
    {
        $this->env('ASA_WEBHOOK_HOST', null);
        $this->assertStringContainsString('ASA_WEBHOOK_HOST', Webhook::path('wo-created')->tryPush()->error);

        $this->env('ASA_WEBHOOK_HOST', 'https://asa.test');
        $this->env('ASA_WEBHOOK_TOKEN', null);
        $this->assertStringContainsString('ASA_WEBHOOK_TOKEN', Webhook::path('wo-created')->tryPush()->error);

        $this->env('ASA_WEBHOOK_TOKEN', self::TOKEN);
        $this->assertStringContainsString('path is required', Webhook::path('/')->tryPush()->error);

        $this->assertCount(0, $this->transport->sent);
    }

    public function testTimeoutFromEnvironmentAndConnectionFailure(): void
    {
        $this->env('ASA_WEBHOOK_TIMEOUT', '3');
        $this->transport->answer = new RuntimeException('Operation timed out');

        $result = Webhook::path('slow')->tryPush();

        $this->assertSame(3, $this->transport->sent[0]['timeout']);
        $this->assertTrue($result->failed());
        $this->assertSame(0, $result->statusCode);
        $this->assertStringContainsString('Operation timed out', $result->error);
    }

    public function testCurlTransportReportsUnreachableHost(): void
    {
        Webhook::useTransport(null);
        $this->env('ASA_WEBHOOK_HOST', 'http://127.0.0.1:1');
        $this->env('ASA_WEBHOOK_TIMEOUT', '2');

        $result = Webhook::path('ping')->tryPush();

        $this->assertTrue($result->failed());
        $this->assertSame(0, $result->statusCode);
    }
}

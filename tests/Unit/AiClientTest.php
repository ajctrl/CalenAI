<?php

declare(strict_types=1);

namespace OCA\CalenAI\Tests\Unit;

use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\AiClient;
use OCA\CalenAI\Service\AiConfig;
use OCA\CalenAI\Service\MessageInterpreter;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AiClientTest extends TestCase {
	private const SECRET_INPUT = '秘密の予定の入力文';

	/** @var list<array{url: string, options: array}> */
	private array $requests = [];
	/** @var list<array{level: string, message: string, context: array}> */
	private array $logs = [];

	private function client(array $settings, int $status, string $responseBody): AiClient {
		$settings += [
			'provider' => AiConfig::PROVIDER_ANTHROPIC,
			'endpoint' => AiConfig::DEFAULT_ENDPOINTS[AiConfig::PROVIDER_ANTHROPIC],
			'model' => 'claude-opus-5-5',
			'apiKey' => 'test-key',
			'effort' => 'low',
			'jsonMode' => AiConfig::JSON_MODE_SCHEMA,
			'allowLocal' => false,
		];
		$config = $this->createStub(AiConfig::class);
		$config->method('getProvider')->willReturn($settings['provider']);
		$config->method('getEndpoint')->willReturn($settings['endpoint']);
		$config->method('getModel')->willReturn($settings['model']);
		$config->method('getApiKey')->willReturn($settings['apiKey']);
		$config->method('getEffort')->willReturn($settings['effort']);
		$config->method('getJsonMode')->willReturn($settings['jsonMode']);
		$config->method('isLocalAllowed')->willReturn($settings['allowLocal']);

		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($responseBody);

		$http = $this->createStub(IClient::class);
		$http->method('post')->willReturnCallback(function (string $url, array $options) use ($response) {
			$this->requests[] = ['url' => $url, 'options' => $options];
			return $response;
		});
		$service = $this->createStub(IClientService::class);
		$service->method('newClient')->willReturn($http);

		$logger = $this->createStub(LoggerInterface::class);
		foreach (['warning', 'error', 'info', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level) {
				$this->logs[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
			});
		}
		return new AiClient($service, $config, $logger);
	}

	private function complete(AiClient $client): array {
		return $client->completeJson('system prompt', self::SECRET_INPUT, MessageInterpreter::schema(), 'calendar_intent');
	}

	private static function anthropicOk(string $text = '{"action":"unclear"}'): string {
		return json_encode([
			'stop_reason' => 'end_turn',
			'content' => [
				['type' => 'thinking', 'thinking' => ''],
				['type' => 'text', 'text' => $text],
			],
		]);
	}

	public function testFollowUpIncludesConversationInOrderForEveryProvider(): void {
		$history = [
			['role' => 'user', 'content' => "OPENAIアップグレード警戒\n11月22日（日） 夜"],
			['role' => 'assistant', 'content' => '開始日時がわかりませんでした。'],
		];
		foreach ([AiConfig::PROVIDER_ANTHROPIC, AiConfig::PROVIDER_OPENAI, AiConfig::PROVIDER_AZURE] as $provider) {
			$anthropic = $provider === AiConfig::PROVIDER_ANTHROPIC;
			$response = $anthropic ? self::anthropicOk() : '{"choices":[{"message":{"content":"{\"action\":\"unclear\"}"}}]}';
			$client = $this->client(['provider' => $provider], 200, $response);
			$config = $this->createStub(AiConfig::class);
			$interpreter = new MessageInterpreter($client, $config);
			$interpreter->interpret('夜間', fn () => [], new \DateTimeImmutable(), new \DateTimeZone('Asia/Tokyo'), [], $history);
			$request = end($this->requests);
			$messages = json_decode($request['options']['body'], true)['messages'];
			if (!$anthropic) {
				$this->assertSame('system', array_shift($messages)['role']);
			}
			$current = array_pop($messages);
			$this->assertSame($history, $messages);
			$this->assertSame('user', $current['role']);
			$this->assertStringEndsWith("<<<\n夜間\n>>>", $current['content']);
		}
	}

	public function testAnthropicRequestShape(): void {
		$result = $this->complete($this->client([], 200, self::anthropicOk()));
		$this->assertSame(['action' => 'unclear'], $result);

		$request = $this->requests[0];
		$this->assertSame('https://api.anthropic.com/v1/messages', $request['url']);
		$headers = $request['options']['headers'];
		$this->assertSame('test-key', $headers['x-api-key']);
		$this->assertSame('2026-10-21', $headers['anthropic-version']);
		$this->assertSame('server-side-fallback-2026-07-01', $headers['anthropic-beta']);
		$this->assertFalse($request['options']['nextcloud']['allow_local_address']);
		// 転送はたどらない（キーと入力文を、確かめていないホストへ送らないため）
		$this->assertFalse($request['options']['allow_redirects']);

		$body = json_decode($request['options']['body'], true);
		$this->assertSame('claude-opus-5-5', $body['model']);
		$this->assertSame('system prompt', $body['system']);
		$this->assertSame([['role' => 'user', 'content' => self::SECRET_INPUT]], $body['messages']);
		$this->assertSame('low', $body['output_config']['effort']);
		$this->assertSame('json_schema', $body['output_config']['format']['type']);
		$this->assertSame(MessageInterpreter::schema(), $body['output_config']['format']['schema']);
		$this->assertSame('default', $body['fallbacks']);
		// この API バージョンで受け付けられないパラメータを送らない
		foreach (['temperature', 'top_p', 'top_k', 'thinking', 'output_format'] as $forbidden) {
			$this->assertArrayNotHasKey($forbidden, $body);
		}
	}

	public function testAnthropicFallbackIsOnlySentWhereSupported(): void {
		$this->complete($this->client(['model' => 'claude-haiku-5-5'], 200, self::anthropicOk()));
		$this->complete($this->client(['endpoint' => 'https://proxy.example.com/v1/messages'], 200, self::anthropicOk()));
		foreach ($this->requests as $request) {
			$this->assertArrayNotHasKey('anthropic-beta', $request['options']['headers']);
			$this->assertArrayNotHasKey('fallbacks', json_decode($request['options']['body'], true));
		}
	}

	public function testAnthropicEffortCanBeOmitted(): void {
		$this->complete($this->client(['effort' => ''], 200, self::anthropicOk()));
		$body = json_decode($this->requests[0]['options']['body'], true);
		$this->assertArrayNotHasKey('effort', $body['output_config']);
	}

	public function testAnthropicRefusalIsReportedBeforeReadingContent(): void {
		$client = $this->client([], 200, json_encode(['stop_reason' => 'refusal', 'content' => []]));
		try {
			$this->complete($client);
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$this->assertSame(502, $e->getStatus());
			$this->assertStringContainsString('見送りました', $e->getMessage());
		}
	}

	public function testOpenAiRequestShape(): void {
		$client = $this->client(
			['provider' => AiConfig::PROVIDER_OPENAI, 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'model' => 'some-model'],
			200,
			json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '{"action":"create"}']]]]),
		);
		$this->assertSame(['action' => 'create'], $this->complete($client));

		$request = $this->requests[0];
		$this->assertSame('Bearer test-key', $request['options']['headers']['Authorization']);
		$this->assertArrayNotHasKey('x-api-key', $request['options']['headers']);
		$body = json_decode($request['options']['body'], true);
		$this->assertSame('some-model', $body['model']);
		$this->assertSame('system', $body['messages'][0]['role']);
		$this->assertSame(self::SECRET_INPUT, $body['messages'][1]['content']);
		$this->assertSame('json_schema', $body['response_format']['type']);
		$this->assertTrue($body['response_format']['json_schema']['strict']);
		$this->assertSame('calendar_intent', $body['response_format']['json_schema']['name']);
		$this->assertArrayNotHasKey('temperature', $body);
	}

	public function testAzureUsesApiKeyHeaderAndJsonModes(): void {
		$ok = json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => "```json\n{\"action\":\"create\"}\n```"]]]]);
		$azure = ['provider' => AiConfig::PROVIDER_AZURE, 'endpoint' => 'https://x.openai.azure.com/openai/deployments/d/chat/completions?api-version=1', 'model' => ''];

		// JSON を強制できないサーバーでも、コードフェンス付きの応答を読める
		$this->assertSame(['action' => 'create'], $this->complete($this->client($azure + ['jsonMode' => AiConfig::JSON_MODE_NONE], 200, $ok)));
		$this->complete($this->client($azure + ['jsonMode' => AiConfig::JSON_MODE_OBJECT], 200, $ok));

		$this->assertSame('test-key', $this->requests[0]['options']['headers']['api-key']);
		$this->assertArrayNotHasKey('Authorization', $this->requests[0]['options']['headers']);
		$first = json_decode($this->requests[0]['options']['body'], true);
		$this->assertArrayNotHasKey('response_format', $first);
		$this->assertArrayNotHasKey('model', $first);
		$second = json_decode($this->requests[1]['options']['body'], true);
		$this->assertSame(['type' => 'json_object'], $second['response_format']);
	}

	public function testOpenAiRefusalAndTruncation(): void {
		$base = ['provider' => AiConfig::PROVIDER_OPENAI, 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'model' => 'm'];
		foreach ([
			['finish_reason' => 'stop', 'message' => ['content' => null, 'refusal' => 'no']],
			['finish_reason' => 'length', 'message' => ['content' => '{"action":']],
		] as $choice) {
			try {
				$this->complete($this->client($base, 200, json_encode(['choices' => [$choice]])));
				$this->fail('expected exception');
			} catch (ServiceException $e) {
				$this->assertSame(502, $e->getStatus());
			}
		}
	}

	public function testMissingKeyOrModelMeansNotConfiguredAndNoRequest(): void {
		foreach ([['apiKey' => ''], ['provider' => AiConfig::PROVIDER_OPENAI, 'model' => ''], ['endpoint' => 'http://insecure.example.com/v1/messages']] as $settings) {
			try {
				$this->complete($this->client($settings, 200, self::anthropicOk()));
				$this->fail('expected exception');
			} catch (ServiceException $e) {
				$this->assertSame(503, $e->getStatus());
			}
		}
		$this->assertSame([], $this->requests);
	}

	public function testRedirectsAreNotFollowedAndAreReportedAsAnError(): void {
		foreach ([301, 302, 307, 308] as $status) {
			try {
				$this->complete($this->client(['provider' => AiConfig::PROVIDER_OPENAI, 'model' => 'm', 'endpoint' => 'https://proxy.example.com/v1/chat/completions'], $status, ''));
				$this->fail('expected exception');
			} catch (ServiceException $e) {
				$this->assertSame(502, $e->getStatus());
				$this->assertStringContainsString('転送', $e->getMessage());
			}
		}
		// 送ったのは最初の1回ずつだけ
		$this->assertCount(4, $this->requests);
		foreach ($this->requests as $request) {
			$this->assertFalse($request['options']['allow_redirects']);
		}
	}

	public function testHttpErrorsGiveHelpfulMessagesAndLogsNeverContainTheInput(): void {
		$errorBody = json_encode(['type' => 'error', 'error' => ['type' => self::SECRET_INPUT, 'message' => 'invalid x-api-key ' . self::SECRET_INPUT]]);
		try {
			$this->complete($this->client([], 401, $errorBody));
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$this->assertSame(502, $e->getStatus());
			$this->assertStringContainsString('API キー', $e->getMessage());
			$this->assertStringNotContainsString(self::SECRET_INPUT, $e->getMessage());
		}

		try {
			$this->complete($this->client([], 200, self::anthropicOk('これは JSON ではありません ' . self::SECRET_INPUT)));
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$this->assertSame(502, $e->getStatus());
		}

		$this->assertNotSame([], $this->logs);
		$serialized = json_encode($this->logs, JSON_UNESCAPED_UNICODE);
		$this->assertStringNotContainsString(self::SECRET_INPUT, $serialized);
		$this->assertStringNotContainsString('test-key', $serialized);
	}

	public function testDecodeJsonObjectIsTolerantButOnlyAcceptsObjects(): void {
		$this->assertSame(['a' => 1], AiClient::decodeJsonObject('{"a":1}'));
		$this->assertSame(['a' => 1], AiClient::decodeJsonObject("はい、こちらです:\n```json\n{\"a\":1}\n```"));
		$this->assertSame(['reply' => '```json {}```'], AiClient::decodeJsonObject('{"reply":"```json {}```"}'));
		$this->assertNull(AiClient::decodeJsonObject('[1,2]'));
		$this->assertNull(AiClient::decodeJsonObject('[{"action":"create"}]'));
		$this->assertNull(AiClient::decodeJsonObject("```json\n[{\"action\":\"create\"}]\n```"));
		$this->assertNull(AiClient::decodeJsonObject('"text"'));
		$this->assertNull(AiClient::decodeJsonObject(''));
	}
}

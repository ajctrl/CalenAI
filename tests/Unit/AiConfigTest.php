<?php

declare(strict_types=1);

namespace OCA\CalenAI\Tests\Unit;

use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\AiConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class AiConfigTest extends TestCase {
	private const OPENAI_KEY = 'sk-openai-secret';

	/** @var array<string, string|bool> アプリ設定の中身 */
	private array $stored = [];

	private function config(): AiConfig {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->stored[$key] ?? $default)
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false): bool => (bool)($this->stored[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->stored[$key] = $value;
			return true;
		});
		$appConfig->method('setValueBool')->willReturnCallback(function (string $app, string $key, bool $value): bool {
			$this->stored[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->stored[$key]);
		});
		return new AiConfig($appConfig);
	}

	private function save(AiConfig $config, array $overrides = []): void {
		$args = $overrides + [
			'provider' => AiConfig::PROVIDER_OPENAI,
			'endpoint' => '',
			'model' => '',
			'apiKey' => null,
			'clearApiKey' => false,
			'effort' => 'low',
			'jsonMode' => AiConfig::JSON_MODE_SCHEMA,
			'allowLocal' => false,
			'candidateMode' => AiConfig::CANDIDATES_ALWAYS,
		];
		$config->save(...$args);
	}

	/** OpenAI の既定の送信先にキーを保存した状態 */
	private function configWithOpenAiKey(): AiConfig {
		$config = $this->config();
		$this->save($config, ['apiKey' => self::OPENAI_KEY]);
		return $config;
	}

	private function assertRejectedAndUnchanged(AiConfig $config, array $overrides): void {
		$before = $this->stored;
		try {
			$this->save($config, $overrides);
			$this->fail('expected exception');
		} catch (ServiceException $e) {
			$this->assertSame(422, $e->getStatus());
		}
		$this->assertSame($before, $this->stored);
	}

	public function testEmptyKeyKeepsTheStoredKeyWhileTheDestinationStaysTheSame(): void {
		$config = $this->configWithOpenAiKey();

		$this->save($config, ['model' => 'gpt-other', 'candidateMode' => AiConfig::CANDIDATES_ON_DEMAND]);
		// 既定の URL を明示しても、パスだけ変えても、同じ送信先
		$this->save($config, ['endpoint' => 'https://API.openai.com:443/v1/responses']);
		// 同じ URL のままプロバイダだけ変える（同じプロキシがキーを受け取る）
		$this->save($config, ['provider' => AiConfig::PROVIDER_ANTHROPIC, 'endpoint' => 'https://api.openai.com/v1/messages']);

		$this->assertSame(self::OPENAI_KEY, $config->getApiKey($config->getEndpoint()));
		$this->assertSame(AiConfig::PROVIDER_ANTHROPIC, $config->getProvider());
	}

	public function testInFlightRequestCanOnlyUseTheKeyForItsDestination(): void {
		$config = $this->configWithOpenAiKey();
		$oldEndpoint = $config->getEndpoint();
		$newEndpoint = 'https://proxy.example.com/v1/chat/completions';
		$this->assertSame('', $config->getApiKey($newEndpoint));
		$this->save($config, ['endpoint' => $newEndpoint, 'apiKey' => 'new-key']);
		$this->assertSame('', $config->getApiKey($oldEndpoint));
		$this->assertSame('new-key', $config->getApiKey($newEndpoint));
	}

	public function testUpgradeBindsLegacyKeyToConfiguredEndpoint(): void {
		$this->stored = ['api_key' => self::OPENAI_KEY, 'endpoint' => 'https://proxy.example.com/v1'];
		$config = $this->config();
		$config->migrateLegacyCredential();
		$config->migrateLegacyCredential();
		$this->assertSame(self::OPENAI_KEY, $config->getApiKey($config->getEndpoint()));
		$this->assertSame('', $config->getApiKey(AiConfig::DEFAULT_ENDPOINTS[AiConfig::PROVIDER_OPENAI]));
		$this->assertArrayNotHasKey('api_key', $this->stored);
	}

	public function testChangingTheDestinationWithoutANewKeyIsRejectedAndSavesNothing(): void {
		$config = $this->configWithOpenAiKey();

		// プロバイダを切り替えて既定の URL が変わる
		$this->assertRejectedAndUnchanged($config, ['provider' => AiConfig::PROVIDER_ANTHROPIC]);
		// 別のホスト・別のポート・http への切り替え
		$this->assertRejectedAndUnchanged($config, ['endpoint' => 'https://proxy.example.com/v1/chat/completions']);
		$this->assertRejectedAndUnchanged($config, ['endpoint' => 'https://api.openai.com:8443/v1/chat/completions']);
		$this->assertRejectedAndUnchanged($config, ['endpoint' => 'http://localhost/v1/chat/completions', 'allowLocal' => true]);
		// 空白だけのキーは「入力なし」と同じ
		$this->assertRejectedAndUnchanged($config, ['provider' => AiConfig::PROVIDER_ANTHROPIC, 'apiKey' => '  ']);

		$this->assertSame(self::OPENAI_KEY, $config->getApiKey($config->getEndpoint()));
		$this->assertSame(AiConfig::DEFAULT_ENDPOINTS[AiConfig::PROVIDER_OPENAI], $config->getEndpoint());
	}

	public function testChangingTheDestinationWorksWithANewKeyOrWhenClearingTheKey(): void {
		$config = $this->configWithOpenAiKey();
		$this->save($config, ['provider' => AiConfig::PROVIDER_ANTHROPIC, 'apiKey' => 'sk-ant-secret']);
		$this->assertSame('sk-ant-secret', $config->getApiKey($config->getEndpoint()));
		$this->assertSame(AiConfig::DEFAULT_ENDPOINTS[AiConfig::PROVIDER_ANTHROPIC], $config->getEndpoint());

		$this->save($config, ['endpoint' => 'https://proxy.example.com/v1/chat/completions', 'clearApiKey' => true]);
		$this->assertFalse($config->hasApiKey());

		// キーがなければ、送信先は自由に変えられる
		$this->save($config, ['provider' => AiConfig::PROVIDER_ANTHROPIC]);
		$this->assertSame(AiConfig::DEFAULT_ENDPOINTS[AiConfig::PROVIDER_ANTHROPIC], $config->getEndpoint());
	}

	public function testHttpsIsAllowedForAnyHost(): void {
		foreach ([false, true] as $allowLocal) {
			AiConfig::assertValidEndpoint('https://api.example.com/v1/chat/completions', $allowLocal);
			AiConfig::assertValidEndpoint('https://192.168.1.20/v1/chat/completions', $allowLocal);
		}
		$this->addToAssertionCount(4);
	}

	public function testHttpNeedsAllowLocal(): void {
		$this->expectException(ServiceException::class);
		AiConfig::assertValidEndpoint('http://localhost:11434/v1/chat/completions', false);
	}

	public function testHttpIsAllowedForLocalAndPrivateHosts(): void {
		$hosts = [
			'localhost', 'LOCALHOST', '127.0.0.1', '127.8.9.10', '10.1.2.3', '172.16.0.5', '172.31.255.254', '192.168.1.20',
			'169.254.10.10', '100.64.0.1', '100.127.255.254', '[::1]', '[fd12:3456::1]', '[fe80::1]',
			'ollama', 'gpu-server-2', 'llm.local', 'host.docker.internal', 'ai.office.lan', 'nas.home.arpa', 'llm.corp.',
		];
		foreach ($hosts as $host) {
			AiConfig::assertValidEndpoint('http://' . $host . ':11434/v1/chat/completions', true);
		}
		$this->addToAssertionCount(count($hosts));
	}

	public function testHttpIsRejectedForPublicHostsEvenWithAllowLocal(): void {
		$hosts = [
			'api.openai.com', 'insecure.example.com', 'llm.example.co.jp', 'localhost.example.com', 'internal.example.com',
			'8.8.8.8', '172.32.0.1', '192.169.1.1', '100.63.255.255', '100.128.0.1', '[2001:4860:4860::8888]',
			// IP の別の書き方（8.8.8.8 や 127.0.0.1 を数字だけで表したもの）
			'134744072', '0x08080808', '2130706433', '0x7f.0.0.1', '127.1',
		];
		foreach ($hosts as $host) {
			try {
				AiConfig::assertValidEndpoint('http://' . $host . '/v1/chat/completions', true);
				$this->fail('http should be rejected for ' . $host);
			} catch (ServiceException $e) {
				$this->assertSame(422, $e->getStatus());
			}
		}
	}

	public function testOtherSchemesAndCredentialsInTheUrlAreRejected(): void {
		foreach (['ftp://localhost/x', 'file:///etc/passwd', 'localhost:11434/v1', 'https://user:pass@api.example.com/v1', ''] as $endpoint) {
			try {
				AiConfig::assertValidEndpoint($endpoint, true);
				$this->fail('should be rejected: ' . $endpoint);
			} catch (ServiceException $e) {
				$this->assertSame(422, $e->getStatus());
			}
		}
	}
}

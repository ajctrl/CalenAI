<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use OCA\CalenAI\Exception\ServiceException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use Psr\Log\LoggerInterface;

/**
 * AI API への通信。プロバイダごとの違い（URL・認証ヘッダ・JSON 強制の方法）をここで吸収する。
 *
 * 公式 SDK は同梱せず、Nextcloud 標準の HTTP クライアントを使う
 * （本体の 3rdparty ライブラリとの衝突を避けるため）。
 *
 * ログには本文（入力文や予定の内容）を残さない。残すのは HTTP ステータスとエラー種別だけ。
 */
class AiClient {
	private const ANTHROPIC_VERSION = '2026-10-21';
	private const ANTHROPIC_FALLBACK_BETA = 'server-side-fallback-2026-07-01';
	private const MAX_TOKENS = 16000;
	private const TIMEOUT_SECONDS = 90;

	public function __construct(
		private IClientService $clientService,
		private AiConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * システム指示と入力を送り、スキーマに沿った JSON オブジェクトを受け取る。
	 *
	 * @param array<string, mixed> $schema JSON Schema（全プロパティ required・additionalProperties: false）
	 * @return array<string, mixed>
	 * @throws ServiceException
	 */
	public function completeJson(string $system, string $user, array $schema, string $schemaName, array $history = []): array {
		$provider = $this->config->getProvider();
		$endpoint = $this->config->getEndpoint();
		$apiKey = $this->config->getApiKey($endpoint);
		$model = $this->config->getModel();
		$allowLocal = $this->config->isLocalAllowed();

		if ($endpoint === '' || $apiKey === '') {
			throw ServiceException::notConfigured();
		}
		if ($model === '' && $provider !== AiConfig::PROVIDER_AZURE) {
			throw ServiceException::notConfigured();
		}
		try {
			AiConfig::assertValidEndpoint($endpoint, $allowLocal);
		} catch (ServiceException) {
			throw ServiceException::notConfigured();
		}

		$messages = [];
		foreach ($history as $turn) {
			$messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
		}
		$messages[] = ['role' => 'user', 'content' => $user];

		if ($provider === AiConfig::PROVIDER_ANTHROPIC) {
			[$headers, $body] = $this->buildAnthropicRequest($endpoint, $apiKey, $model, $system, $messages, $schema);
		} else {
			[$headers, $body] = $this->buildOpenAiRequest($provider, $apiKey, $model, $system, $messages, $schema, $schemaName);
		}

		$response = $this->send($endpoint, $headers, $body, $allowLocal);

		$text = $provider === AiConfig::PROVIDER_ANTHROPIC
			? $this->readAnthropicResponse($response)
			: $this->readOpenAiResponse($response);

		$decoded = self::decodeJsonObject($text);
		if ($decoded === null) {
			$this->logger->warning('CalenAI: AI response was not a JSON object', ['provider' => $provider]);
			throw ServiceException::ai('AIの応答を読み取れませんでした。もう一度お試しください。');
		}
		return $decoded;
	}

	/**
	 * @return array{0: array<string, string>, 1: array<string, mixed>}
	 */
	private function buildAnthropicRequest(string $endpoint, string $apiKey, string $model, string $system, array $messages, array $schema): array {
		$headers = [
			'Content-Type' => 'application/json',
			'x-api-key' => $apiKey,
			'anthropic-version' => self::ANTHROPIC_VERSION,
		];
		$outputConfig = [
			'format' => ['type' => 'json_schema', 'schema' => $schema],
		];
		$effort = $this->config->getEffort();
		if ($effort !== '') {
			$outputConfig['effort'] = $effort;
		}
		// thinking / temperature は送らない: 現行モデルでは thinking は自動で、
		// サンプリング系のパラメータはこの API バージョンでは受け付けられない。
		$body = [
			'model' => $model,
			'max_tokens' => self::MAX_TOKENS,
			'system' => $system,
			'messages' => $messages,
			'output_config' => $outputConfig,
		];

		// 安全分類で拒否されたとき、Anthropic 側で推奨モデルに自動で切り替えてもらう。
		// 対応しているのは Anthropic 直結かつ対応モデルのときだけ。
		if (self::supportsAnthropicFallback($endpoint, $model)) {
			$headers['anthropic-beta'] = self::ANTHROPIC_FALLBACK_BETA;
			$body['fallbacks'] = 'default';
		}
		return [$headers, $body];
	}

	private static function supportsAnthropicFallback(string $endpoint, string $model): bool {
		$host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
		if ($host !== 'api.anthropic.com') {
			return false;
		}
		return preg_match('/^claude-(?:(?:fable|mythos|opus)-5(?:-\d+)?|sonnet-5-5)$/', $model) === 1;
	}

	/**
	 * @return array{0: array<string, string>, 1: array<string, mixed>}
	 */
	private function buildOpenAiRequest(string $provider, string $apiKey, string $model, string $system, array $messages, array $schema, string $schemaName): array {
		$headers = ['Content-Type' => 'application/json'];
		if ($provider === AiConfig::PROVIDER_AZURE) {
			$headers['api-key'] = $apiKey;
		} else {
			$headers['Authorization'] = 'Bearer ' . $apiKey;
		}

		$body = [
			'messages' => [
				['role' => 'system', 'content' => $system],
				...$messages,
			],
		];
		if ($model !== '') {
			$body['model'] = $model;
		}
		switch ($this->config->getJsonMode()) {
			case AiConfig::JSON_MODE_SCHEMA:
				$body['response_format'] = [
					'type' => 'json_schema',
					'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
				];
				break;
			case AiConfig::JSON_MODE_OBJECT:
				$body['response_format'] = ['type' => 'json_object'];
				break;
		}
		return [$headers, $body];
	}

	/**
	 * @param array<string, string> $headers
	 * @param array<string, mixed> $body
	 * @return array<string, mixed> デコード済みのレスポンス
	 * @throws ServiceException
	 */
	private function send(string $endpoint, array $headers, array $body, bool $allowLocal): array {
		try {
			$response = $this->clientService->newClient()->post($endpoint, [
				'headers' => $headers,
				'body' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
				'timeout' => self::TIMEOUT_SECONDS,
				'http_errors' => false,
				// 転送をたどると、API キーのヘッダと入力文が、確かめていない別のホストへ送られてしまう
				'allow_redirects' => false,
				'nextcloud' => ['allow_local_address' => $allowLocal],
			]);
		} catch (LocalServerException $e) {
			throw ServiceException::ai('AI API のアドレスがローカルネットワーク内のため接続できません。管理画面で「ローカルアドレスを許可」を有効にしてください。', $e);
		} catch (\Throwable $e) {
			// 例外メッセージに URL やキーが入ることがあるので、種類だけ残す
			$this->logger->warning('CalenAI: AI API request failed', ['exception' => get_class($e)]);
			throw ServiceException::ai('AI API に接続できませんでした。しばらくしてからもう一度お試しください。');
		}

		$status = $response->getStatusCode();

		if ($status >= 300 && $status < 400) {
			$this->logger->warning('CalenAI: AI API answered with a redirect', ['status' => $status]);
			throw ServiceException::ai('AI API が別の URL への転送を返しました（' . $status . '）。転送先には接続しません。エンドポイント URL を確かめてください。');
		}
		if ($status >= 400) {
			$this->logger->warning('CalenAI: AI API returned an error', ['status' => $status]);
			throw ServiceException::ai(self::messageForStatus($status));
		}
		$raw = $response->getBody();
		$decoded = json_decode(is_string($raw) ? $raw : (string)stream_get_contents($raw), true);
		if (!is_array($decoded)) {
			$this->logger->warning('CalenAI: AI API response was not JSON', ['status' => $status]);
			throw ServiceException::ai('AI API の応答を読み取れませんでした。エンドポイント URL を確かめてください。');
		}
		return $decoded;
	}

	private static function messageForStatus(int $status): string {
		$detail = '（' . $status . '）';
		return match (true) {
			$status === 401, $status === 403 => 'AI API の認証に失敗しました。API キーを確かめてください。' . $detail,
			$status === 404 => 'AI API のエンドポイントまたはモデルが見つかりません。設定を確かめてください。' . $detail,
			$status === 429 => 'AI API が混み合っているか、利用上限に達しています。少し待ってからお試しください。' . $detail,
			$status >= 500 => 'AI API 側でエラーが起きました。しばらくしてからもう一度お試しください。' . $detail,
			default => 'AI API がリクエストを受け付けませんでした。モデル名などの設定を確かめてください。' . $detail,
		};
	}

	/**
	 * @param array<string, mixed> $response
	 * @throws ServiceException
	 */
	private function readAnthropicResponse(array $response): string {
		// 拒否は HTTP 200 で返るので、本文を読む前に stop_reason を見る
		$stopReason = $response['stop_reason'] ?? null;
		if ($stopReason === 'refusal') {
			throw ServiceException::ai('AIがこの入力の処理を見送りました。言い方を変えてお試しください。');
		}
		if ($stopReason === 'max_tokens') {
			throw ServiceException::ai('AIの応答が途中で切れました。もう一度お試しください。');
		}

		$text = '';
		foreach (is_array($response['content'] ?? null) ? $response['content'] : [] as $block) {
			if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
				$text .= $block['text'];
			}
		}
		return $text;
	}

	/**
	 * @param array<string, mixed> $response
	 * @throws ServiceException
	 */
	private function readOpenAiResponse(array $response): string {
		$choice = $response['choices'][0] ?? null;
		if (!is_array($choice)) {
			throw ServiceException::ai('AI API の応答を読み取れませんでした。エンドポイント URL を確かめてください。');
		}
		$message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
		if (is_string($message['refusal'] ?? null) && $message['refusal'] !== '') {
			throw ServiceException::ai('AIがこの入力の処理を見送りました。言い方を変えてお試しください。');
		}
		if (($choice['finish_reason'] ?? null) === 'length') {
			throw ServiceException::ai('AIの応答が途中で切れました。もう一度お試しください。');
		}

		$content = $message['content'] ?? '';
		if (is_array($content)) {
			// 一部の互換サーバーは content をブロックの配列で返す
			$text = '';
			foreach ($content as $part) {
				if (is_array($part) && is_string($part['text'] ?? null)) {
					$text .= $part['text'];
				}
			}
			return $text;
		}
		return is_string($content) ? $content : '';
	}

	/**
	 * 応答の文字列から JSON オブジェクトを取り出す。
	 * JSON を強制できないサーバー向けに、コードフェンスや前後の文も許容する。
	 *
	 * @return array<string, mixed>|null
	 */
	public static function decodeJsonObject(string $text): ?array {
		$text = trim($text);
		if ($text === '') {
			return null;
		}
		$decoded = json_decode($text, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			// 説明やフェンス付きでも、一番外側の JSON を読む。配列から操作を抜き出さない。
			if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $match) === 1) {
				$payload = $match[1];
			} else {
				$first = strcspn($text, '{[');
				$last = strrpos($text, '}');
				if (($text[$first] ?? null) !== '{' || $last === false) {
					return null;
				}
				$payload = substr($text, $first, $last - $first + 1);
			}
			$decoded = json_decode($payload, true);
		}
		return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
	}
}

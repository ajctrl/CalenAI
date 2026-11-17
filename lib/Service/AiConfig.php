<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use OCA\CalenAI\AppInfo\Application;
use OCA\CalenAI\Exception\ServiceException;
use OCP\IAppConfig;

/**
 * 管理者が設定する AI API の接続情報。
 */
class AiConfig {
	public const PROVIDER_ANTHROPIC = 'anthropic';
	public const PROVIDER_OPENAI = 'openai';
	public const PROVIDER_AZURE = 'azure';
	public const PROVIDERS = [self::PROVIDER_ANTHROPIC, self::PROVIDER_OPENAI, self::PROVIDER_AZURE];
	/** 何も設定していないときのプロバイダ */
	public const DEFAULT_PROVIDER = self::PROVIDER_OPENAI;

	public const DEFAULT_ENDPOINTS = [
		self::PROVIDER_ANTHROPIC => 'https://api.anthropic.com/v1/messages',
		self::PROVIDER_OPENAI => 'https://api.openai.com/v1/chat/completions',
		self::PROVIDER_AZURE => '',
	];
	public const DEFAULT_MODELS = [
		self::PROVIDER_ANTHROPIC => 'claude-opus-5-5',
		self::PROVIDER_OPENAI => 'gpt-6-luna',
		self::PROVIDER_AZURE => '',
	];

	/** 空文字は「effort を送らない」（対応していないモデル向け） */
	public const EFFORTS = ['', 'low', 'medium', 'high', 'xhigh', 'max'];
	public const DEFAULT_EFFORT = 'low';

	/** OpenAI 互換・Azure で JSON を強制する方法 */
	public const JSON_MODE_SCHEMA = 'schema';
	public const JSON_MODE_OBJECT = 'object';
	public const JSON_MODE_NONE = 'none';
	public const JSON_MODES = [self::JSON_MODE_SCHEMA, self::JSON_MODE_OBJECT, self::JSON_MODE_NONE];

	/** 「最近の予定」（件名・日時）を AI に送るタイミング */
	public const CANDIDATES_ALWAYS = 'always';
	public const CANDIDATES_ON_DEMAND = 'on_demand';
	public const CANDIDATE_MODES = [self::CANDIDATES_ALWAYS, self::CANDIDATES_ON_DEMAND];

	private const KEY_PROVIDER = 'provider';
	private const KEY_ENDPOINT = 'endpoint';
	private const KEY_MODEL = 'model';
	private const KEY_CREDENTIAL = 'api_credential';
	private const KEY_EFFORT = 'effort';
	private const KEY_JSON_MODE = 'json_mode';
	private const KEY_ALLOW_LOCAL = 'allow_local';
	private const KEY_CANDIDATE_MODE = 'candidate_mode';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getProvider(): string {
		$provider = $this->appConfig->getValueString(Application::APP_ID, self::KEY_PROVIDER, self::DEFAULT_PROVIDER);
		return in_array($provider, self::PROVIDERS, true) ? $provider : self::DEFAULT_PROVIDER;
	}

	/** 管理者が入力した値（未入力なら空） */
	public function getCustomEndpoint(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_ENDPOINT, '');
	}

	/** 実際に使う URL（未入力ならプロバイダの既定） */
	public function getEndpoint(): string {
		$custom = $this->getCustomEndpoint();
		return $custom !== '' ? $custom : self::DEFAULT_ENDPOINTS[$this->getProvider()];
	}

	public function getCustomModel(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_MODEL, '');
	}

	public function getModel(): string {
		$custom = $this->getCustomModel();
		return $custom !== '' ? $custom : self::DEFAULT_MODELS[$this->getProvider()];
	}

	public function getApiKey(string $endpoint): string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, self::KEY_CREDENTIAL, '', lazy: true);
		$credential = json_decode($stored, true);
		if (($credential['origin'] ?? null) !== self::endpointOrigin($endpoint)) {
			return '';
		}
		return $credential['key'] ?? '';
	}

	public function hasApiKey(): bool {
		return $this->getApiKey($this->getEndpoint()) !== '';
	}

	public function getEffort(): string {
		$effort = $this->appConfig->getValueString(Application::APP_ID, self::KEY_EFFORT, self::DEFAULT_EFFORT);
		return in_array($effort, self::EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
	}

	public function getJsonMode(): string {
		$mode = $this->appConfig->getValueString(Application::APP_ID, self::KEY_JSON_MODE, self::JSON_MODE_SCHEMA);
		return in_array($mode, self::JSON_MODES, true) ? $mode : self::JSON_MODE_SCHEMA;
	}

	public function isLocalAllowed(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_ALLOW_LOCAL, false);
	}

	/**
	 * always: 毎回送る（1回の呼び出しで判定でき、精度が高い）
	 * on_demand: 修正・削除と判定されたときだけ送る（送るデータは最小。修正は2回呼び出しになる）
	 */
	public function getCandidateMode(): string {
		$mode = $this->appConfig->getValueString(Application::APP_ID, self::KEY_CANDIDATE_MODE, self::CANDIDATES_ALWAYS);
		return in_array($mode, self::CANDIDATE_MODES, true) ? $mode : self::CANDIDATES_ALWAYS;
	}

	/**
	 * 画面に返してよい設定。API キーそのものは含めない。
	 *
	 * @return array<string, mixed>
	 */
	public function toPublicArray(): array {
		return [
			'provider' => $this->getProvider(),
			'endpoint' => $this->getCustomEndpoint(),
			'model' => $this->getCustomModel(),
			'hasApiKey' => $this->hasApiKey(),
			'effort' => $this->getEffort(),
			'jsonMode' => $this->getJsonMode(),
			'allowLocal' => $this->isLocalAllowed(),
			'candidateMode' => $this->getCandidateMode(),
			'defaults' => [
				'endpoints' => self::DEFAULT_ENDPOINTS,
				'models' => self::DEFAULT_MODELS,
			],
		];
	}

	/**
	 * @param string|null $apiKey null または空なら今のキーを保つ。
	 *                            ただし送信先（スキーム・ホスト・ポート）が変わるときは、入力し直すか削除するかが必要
	 * @throws ServiceException 値が正しくないとき（このときは何も保存しない）
	 */
	public function save(
		string $provider,
		string $endpoint,
		string $model,
		?string $apiKey,
		bool $clearApiKey,
		string $effort,
		string $jsonMode,
		bool $allowLocal,
		string $candidateMode,
	): void {
		if (!in_array($provider, self::PROVIDERS, true)) {
			throw ServiceException::invalid('プロバイダの指定が正しくありません。');
		}
		if (!in_array($effort, self::EFFORTS, true)) {
			throw ServiceException::invalid('effort の指定が正しくありません。');
		}
		if (!in_array($jsonMode, self::JSON_MODES, true)) {
			throw ServiceException::invalid('JSON 出力方式の指定が正しくありません。');
		}
		if (!in_array($candidateMode, self::CANDIDATE_MODES, true)) {
			throw ServiceException::invalid('候補の送信方法の指定が正しくありません。');
		}

		$endpoint = trim($endpoint);
		$model = trim($model);
		if ($endpoint !== '') {
			self::assertValidEndpoint($endpoint, $allowLocal);
		} elseif (self::DEFAULT_ENDPOINTS[$provider] === '') {
			throw ServiceException::invalid('このプロバイダではエンドポイント URL の入力が必要です。');
		}
		if (strlen($model) > 200 || preg_match('/[\x00-\x1F\x7F]/', $model) === 1) {
			throw ServiceException::invalid('モデル名が正しくありません。');
		}
		if ($apiKey !== null) {
			$apiKey = trim($apiKey);
			if (strlen($apiKey) > 4000 || preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1) {
				throw ServiceException::invalid('API キーが正しくありません。');
			}
		}

		// 保存済みのキーは、それを入力したときの送信先にだけ送る
		$newEndpoint = $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINTS[$provider];
		$hasNewKey = $apiKey !== null && $apiKey !== '';
		if (!$clearApiKey && !$hasNewKey && $this->hasApiKey()) {
			if (self::endpointOrigin($newEndpoint) !== self::endpointOrigin($this->getEndpoint())) {
				throw ServiceException::invalid('送信先が変わるので、API キーを入力し直してください（保存済みのキーは、別の送信先には送りません）。');
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, self::KEY_PROVIDER, $provider);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_ENDPOINT, $endpoint);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_MODEL, $model);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_EFFORT, $effort);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_JSON_MODE, $jsonMode);
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_ALLOW_LOCAL, $allowLocal);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CANDIDATE_MODE, $candidateMode);

		if ($clearApiKey) {
			$this->appConfig->deleteKey(Application::APP_ID, self::KEY_CREDENTIAL);
		} elseif ($apiKey !== null && $apiKey !== '') {
			$this->storeCredential($newEndpoint, $apiKey);
		}
	}

	private function storeCredential(string $endpoint, string $apiKey): void {
		$value = json_encode(['origin' => self::endpointOrigin($endpoint), 'key' => $apiKey], JSON_THROW_ON_ERROR);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CREDENTIAL, $value, lazy: true, sensitive: true);
	}

	/** アップグレード中に、従来のキーをその時点の送信先に結び付ける。 */
	public function migrateLegacyCredential(): void {
		$key = $this->appConfig->getValueString(Application::APP_ID, 'api_key', '', lazy: true);
		if ($key !== '') {
			if ($this->appConfig->getValueString(Application::APP_ID, self::KEY_CREDENTIAL, '', lazy: true) === '') {
				$this->storeCredential($this->getEndpoint(), $key);
			}
			$this->appConfig->deleteKey(Application::APP_ID, 'api_key');
		}
	}

	/**
	 * API キーを平文で流さないため、基本は https だけ。
	 * 「ローカルアドレスを許可」のときだけ、ローカル・プライベートの宛先への http も認める（社内の推論サーバー向け）。
	 *
	 * @throws ServiceException
	 */
	public static function assertValidEndpoint(string $endpoint, bool $allowLocal): void {
		$parts = parse_url($endpoint);
		$scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
		$host = is_array($parts) ? ($parts['host'] ?? '') : '';
		if ($host === '' || strlen($endpoint) > 2000 || isset($parts['user']) || isset($parts['pass'])) {
			throw ServiceException::invalid('エンドポイント URL が正しくありません。');
		}
		if ($scheme === 'https') {
			return;
		}
		if ($scheme !== 'http' || !$allowLocal) {
			throw ServiceException::invalid('エンドポイント URL は https:// で始めてください（http:// は「ローカルアドレスを許可」のときだけ使えます）。');
		}
		if (!self::isLocalHost($host)) {
			throw ServiceException::invalid('http:// が使えるのは、ローカルやプライベートのアドレス（localhost、192.168.x.x など）だけです。それ以外は https:// にしてください。');
		}
	}

	/** 末尾がこれなら社内向けの名前とみなす（どれもインターネットでは使われていない） */
	private const LOCAL_SUFFIXES = ['localhost', 'local', 'internal', 'intranet', 'lan', 'home.arpa', 'corp', 'home', 'private'];

	/**
	 * http を認めてよい宛先か。名前解決はせず、書かれたホストだけで判定する。
	 * IP ならループバック・プライベート・リンクローカル、名前なら「ドットなし」か社内向けの末尾だけ。
	 */
	private static function isLocalHost(string $host): bool {
		$host = rtrim(strtolower(trim($host, '[]')), '.');
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
				return true;
			}
			// 100.64.0.0/10（VPN などで使う共有アドレス）
			$long = ip2long($host);
			return $long !== false && ($long & 0xFFC00000) === 0x64400000;
		}
		// 数字で始まるものは、IP の別の書き方（2130706433 や 0x7f.1）のことがあるので通さない
		if (preg_match('/^[a-z][a-z0-9-]*(\.[a-z0-9-]+)*$/', $host) !== 1) {
			return false;
		}
		if (!str_contains($host, '.')) {
			return true;
		}
		foreach (self::LOCAL_SUFFIXES as $suffix) {
			if (str_ends_with($host, '.' . $suffix)) {
				return true;
			}
		}
		return false;
	}

	/** キーの送信先を比べるための「スキーム://ホスト:ポート」。読み取れなければ空 */
	private static function endpointOrigin(string $endpoint): string {
		$parts = parse_url($endpoint);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return '';
		}
		$scheme = strtolower($parts['scheme']);
		$port = $parts['port'] ?? ($scheme === 'http' ? 80 : 443);
		return $scheme . '://' . rtrim(strtolower($parts['host']), '.') . ':' . $port;
	}
}

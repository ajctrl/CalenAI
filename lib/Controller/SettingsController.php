<?php

declare(strict_types=1);

namespace OCA\CalenAI\Controller;

use OCA\CalenAI\AppInfo\Application;
use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\AiConfig;
use OCA\CalenAI\Service\MessageInterpreter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * 管理者設定（AI API の接続情報）。
 *
 * NoAdminRequired を付けていないので、どのメソッドも管理者だけが呼べる。
 * API キーは保存するだけで、画面には返さない。
 */
class SettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private AiConfig $config,
		private MessageInterpreter $interpreter,
		private IDateTimeZone $dateTimeZone,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[FrontpageRoute(verb: 'GET', url: '/api/admin/settings')]
	public function show(): JSONResponse {
		return new JSONResponse($this->config->toPublicArray());
	}

	/**
	 * @param string|null $apiKey 空なら今のキーを保つ
	 */
	#[FrontpageRoute(verb: 'PUT', url: '/api/admin/settings')]
	public function save(
		string $provider = AiConfig::DEFAULT_PROVIDER,
		string $endpoint = '',
		string $model = '',
		?string $apiKey = null,
		bool $clearApiKey = false,
		string $effort = AiConfig::DEFAULT_EFFORT,
		string $jsonMode = AiConfig::JSON_MODE_SCHEMA,
		bool $allowLocal = false,
		string $candidateMode = AiConfig::CANDIDATES_ALWAYS,
	): JSONResponse {
		try {
			$this->config->save($provider, $endpoint, $model, $apiKey, $clearApiKey, $effort, $jsonMode, $allowLocal, $candidateMode);
		} catch (ServiceException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
		return new JSONResponse($this->config->toPublicArray());
	}

	/**
	 * 接続テスト。決まった例文を保存済みの設定で解析してみる（カレンダーには何も保存しない）。
	 */
	#[FrontpageRoute(verb: 'POST', url: '/api/admin/test')]
	public function test(): JSONResponse {
		try {
			$result = $this->interpreter->interpret(
				'明日の10時から歯医者',
				static fn (): array => [],
				$this->timeFactory->now(),
				$this->dateTimeZone->getTimeZone(),
			);
		} catch (ServiceException $e) {
			return new JSONResponse(['ok' => false, 'message' => $e->getMessage()], $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('CalenAI: connection test failed', [
				'class' => get_class($e),
				'at' => basename($e->getFile()) . ':' . $e->getLine(),
			]);
			return new JSONResponse(['ok' => false, 'message' => '接続テスト中にエラーが起きました。'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$ok = $result['action'] === MessageInterpreter::ACTION_CREATE;
		return new JSONResponse([
			'ok' => $ok,
			'message' => $ok
				? '接続できました。例文「明日の10時から歯医者」を正しく解析できました。'
				: '接続はできましたが、例文を予定として解析できませんでした。モデルや JSON 出力方式を確かめてください。',
			'action' => $result['action'],
			'event' => $result['event'] ?? null,
		]);
	}
}

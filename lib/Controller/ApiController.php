<?php

declare(strict_types=1);

namespace OCA\CalenAI\Controller;

use DateTimeZone;
use OCA\CalenAI\AppInfo\Application;
use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\EventManager;
use OCA\CalenAI\Service\EventMerger;
use OCA\CalenAI\Service\MessageInterpreter;
use OCA\CalenAI\Service\TimezoneResolver;
use OCA\CalenAI\Service\UserTimezone;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * 画面から呼ばれる API。
 *
 * どのメソッドもログインが必要で、CSRF トークン（requesttoken ヘッダ）を確認する
 * （Nextcloud の既定の動作。NoCSRFRequired を付けていない）。
 * 予定の ID は必ず「ログイン中のユーザーの履歴」から探すので、他ユーザーの ID は 404 になる。
 */
class ApiController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private EventManager $eventManager,
		private MessageInterpreter $interpreter,
		private UserTimezone $userTimezone,
		private ITimeFactory $timeFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * 書き込み可能なカレンダーの一覧と既定の保存先、使っているタイムゾーン。
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/calendars')]
	public function calendars(?string $timezone = null): JSONResponse {
		return $this->respond(fn (string $userId) => $this->settings($userId, $timezone));
	}

	/**
	 * ユーザー設定を変える。渡された項目だけを変える。
	 *
	 * @param int|null $calendarId 既定の保存先
	 * @param string|null $timezoneSetting 予定の日時を解釈するタイムゾーン。空文字なら「自動」に戻す。
	 * @param bool|null $autoCalendar 予定の内容からカレンダーを自動で選ぶかどうか
	 * @param array<int|string, mixed>|null $hints カレンダー ID => 「ここに入れるもの」の説明
	 * @param string|null $timezone ブラウザが報告したタイムゾーン
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/settings')]
	public function saveSettings(?int $calendarId = null, ?string $timezoneSetting = null, ?bool $autoCalendar = null, ?array $hints = null, ?string $timezone = null): JSONResponse {
		return $this->respond(function (string $userId) use ($calendarId, $timezoneSetting, $autoCalendar, $hints, $timezone) {
			if ($calendarId !== null) {
				$this->eventManager->setDefaultCalendar($userId, $calendarId);
			}
			if ($autoCalendar !== null) {
				$this->eventManager->setAutoCalendar($userId, $autoCalendar);
			}
			if ($hints !== null) {
				$this->eventManager->setHints($userId, $hints);
			}
			if ($timezoneSetting !== null) {
				$this->userTimezone->setSetting($userId, $timezoneSetting);
			}
			return $this->settings($userId, $timezone);
		});
	}

	/**
	 * @return array<string, mixed>
	 */
	private function settings(string $userId, ?string $browserTimezone): array {
		return $this->eventManager->getCalendars($userId) + [
			'timezone' => $this->userTimezone->describe($userId, $browserTimezone),
		];
	}

	/**
	 * 最近このアプリで作成した予定（新しい順）。
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/history')]
	public function history(?string $timezone = null): JSONResponse {
		return $this->respond(fn (string $userId) => [
			'events' => $this->eventManager->listRecent($userId, $this->userTimezone->get($userId, $timezone)),
		]);
	}

	/**
	 * 入力文を AI で解析して実行する。
	 * 作成・修正は確認なしで即実行し、削除は「確認待ち」を返すだけで実行しない。
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	#[FrontpageRoute(verb: 'POST', url: '/api/message')]
	public function message(string $text = '', ?string $timezone = null, array $history = []): JSONResponse {
		return $this->respond(function (string $userId) use ($text, $timezone, $history) {
			$tz = $this->userTimezone->get($userId, $timezone);
			$result = $this->interpreter->interpret(
				$text,
				fn () => $this->eventManager->listRecent($userId, $tz, MessageInterpreter::MAX_CANDIDATES),
				$this->timeFactory->now(),
				$tz,
				$this->eventManager->getRoutingCalendars($userId),
				$history,
			);

			switch ($result['action']) {
				case MessageInterpreter::ACTION_CREATE:
					return [
						'status' => 'created',
						'reply' => $result['reply'],
						'event' => $this->eventManager->create($userId, $result['event'], $tz, $result['calendarId']),
					];

				case MessageInterpreter::ACTION_UPDATE:
					$updated = $this->eventManager->update(
						$userId,
						(int)$result['target']['id'],
						$result['patch'],
						$result['changedFields'],
						$tz,
						$result['target']['etag'],
						$result['calendarId'],
						$result['target']['revision'],
					);
					return [
						'status' => 'updated',
						'reply' => $updated['changes'] === [] ? 'すでにその内容になっています。変更はありません。' : $result['reply'],
						'event' => $updated['event'],
						'changes' => $updated['changes'],
					];

				case MessageInterpreter::ACTION_DELETE:
					// ここでは削除しない。画面が確認を出し、OK のときだけ DELETE を呼ぶ。
					// 出席者のいる予定は削除できないので、確認を出す前に断る。
					EventManager::assertNoAttendees($result['target']);
					return [
						'status' => 'confirm_delete',
						'reply' => $result['reply'],
						'event' => $result['target'],
					];

				case MessageInterpreter::ACTION_AMBIGUOUS:
					return [
						'status' => 'ambiguous',
						'intent' => $result['intent'],
						'reply' => $result['reply'],
						'candidates' => $result['candidates'],
					];

				default:
					return [
						'status' => 'unclear',
						'usedCandidates' => $result['usedCandidates'] ?? false,
						'reply' => $result['reply'],
					];
			}
		});
	}

	/**
	 * カードのフォームからの修正。
	 *
	 * @param array<string, mixed> $event フォームの内容（title, start, end, allDay, location, description）。
	 *   calendarId を含めると、そのカレンダーへ移す。
	 * @param string|null $etag 画面が見ていた版。別の場所で変更されていたら 409 を返す。
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'PUT', url: '/api/events/{id}')]
	public function update(int $id, array $event = [], ?string $etag = null, ?string $timezone = null, ?int $revision = null, ?string $displayTimezone = null): JSONResponse {
		return $this->respond(function (string $userId) use ($id, $event, $etag, $timezone, $revision, $displayTimezone) {
			if ($displayTimezone !== null && !TimezoneResolver::isValid($displayTimezone)) {
				throw ServiceException::invalid('タイムゾーンの指定が正しくありません。');
			}
			if (isset($event['allDay']) && !is_bool($event['allDay'])) {
				$event['allDay'] = filter_var($event['allDay'], FILTER_VALIDATE_BOOLEAN);
			}
			$moveTo = isset($event['calendarId']) && is_numeric($event['calendarId']) ? (int)$event['calendarId'] : null;
			return $this->eventManager->update(
				$userId,
				$id,
				$event,
				array_values(array_intersect(EventMerger::FIELDS, array_keys($event))),
				$displayTimezone === null ? $this->userTimezone->get($userId, $timezone) : new DateTimeZone($displayTimezone),
				$etag,
				$moveTo,
				$revision,
			);
		});
	}

	/**
	 * 直前の修正を取り消す。
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/api/events/{id}/undo')]
	public function undo(int $id, ?string $timezone = null, ?int $revision = null): JSONResponse {
		return $this->respond(fn (string $userId) => $this->eventManager->undo($userId, $id, $this->userTimezone->get($userId, $timezone), $revision));
	}

	/**
	 * 削除。画面で確認した後にだけ呼ばれる。
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/events/{id}')]
	public function destroy(int $id, ?int $revision = null, ?string $timezone = null): JSONResponse {
		return $this->respond(function (string $userId) use ($id, $revision, $timezone) {
			$this->eventManager->delete($userId, $id, $this->userTimezone->get($userId, $timezone), $revision);
			return ['deleted' => true, 'id' => $id];
		});
	}

	/**
	 * @param callable(string): array<string, mixed> $action
	 */
	private function respond(callable $action): JSONResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new JSONResponse(['message' => 'ログインが必要です。'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			return new JSONResponse($action($userId));
		} catch (ServiceException $e) {
			if ($e->getStatus() >= 500) {
				$this->logger->error('CalenAI: request failed (' . $e->getStatus() . ')', self::logContext($e));
			}
			return new JSONResponse(['message' => $e->getMessage()] + $e->getData(), $e->getStatus());
		} catch (\Throwable $e) {
			$this->logger->error('CalenAI: unexpected error', self::logContext($e));
			return new JSONResponse(['message' => '処理中にエラーが起きました。もう一度お試しください。'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * 入力文や予定の内容がログに入らないよう、例外の種類・コード・場所だけを残す（原因の例外も同様）。
	 *
	 * @return array<string, mixed>
	 */
	private static function logContext(\Throwable $e): array {
		$context = [];
		$depth = 0;
		for ($current = $e; $current !== null && $depth < 5; $current = $current->getPrevious(), $depth++) {
			$context[$depth === 0 ? 'error' : 'cause' . $depth] = [
				'class' => get_class($current),
				'code' => $current->getCode(),
				'at' => basename($current->getFile()) . ':' . $current->getLine(),
			];
		}
		return $context;
	}
}

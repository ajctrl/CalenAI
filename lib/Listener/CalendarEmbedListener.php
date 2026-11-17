<?php

declare(strict_types=1);

namespace OCA\CalenAI\Listener;

use OCA\CalenAI\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Calendar アプリのページに、CalenAI の入力パネル（右下のボタン）を差し込む。
 *
 * Calendar には他のアプリが画面を足すための公式の仕組みがないので、
 * Nextcloud 本体の「ページを描く直前」のイベントで自分のスクリプトを読み込ませる。
 * ログイン中の利用者が見る通常のページだけが対象（公開リンクや埋め込み表示には出さない）。
 *
 * @template-implements IEventListener<BeforeTemplateRenderedEvent>
 */
class CalendarEmbedListener implements IEventListener {
	private const CALENDAR_APP_ID = 'calendar';

	#[\Override]
	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || !$event->isLoggedIn()) {
			return;
		}
		$response = $event->getResponse();
		if ($response->getApp() !== self::CALENDAR_APP_ID
			|| $response->getRenderAs() !== TemplateResponse::RENDER_AS_USER) {
			return;
		}

		Util::addScript(Application::APP_ID, 'api');
		Util::addScript(Application::APP_ID, 'main');
		Util::addStyle(Application::APP_ID, 'main');
	}
}

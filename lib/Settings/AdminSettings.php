<?php

declare(strict_types=1);

namespace OCA\CalenAI\Settings;

use OCA\CalenAI\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * 管理画面の「人工知能」セクションに AI API の設定を表示する。
 */
class AdminSettings implements ISettings {
	#[\Override]
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'api');
		Util::addScript(Application::APP_ID, 'admin');
		Util::addStyle(Application::APP_ID, 'admin');
		return new TemplateResponse(Application::APP_ID, 'admin', [], TemplateResponse::RENDER_AS_BLANK);
	}

	#[\Override]
	public function getSection(): string {
		return 'ai';
	}

	#[\Override]
	public function getPriority(): int {
		return 60;
	}
}

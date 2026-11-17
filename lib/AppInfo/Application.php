<?php

declare(strict_types=1);

namespace OCA\CalenAI\AppInfo;

use OCA\CalenAI\Listener\CalendarEmbedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'calenai';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// Calendar アプリのページに、右下の「AI」ボタンと入力パネルを差し込む
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, CalendarEmbedListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}

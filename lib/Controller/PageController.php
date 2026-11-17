<?php

declare(strict_types=1);

namespace OCA\CalenAI\Controller;

use OCA\CalenAI\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * AI予定追加の画面。ログインしていなければ Nextcloud がログイン画面へ送る。
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/')]
	public function index(): TemplateResponse {
		Util::addScript(Application::APP_ID, 'api');
		Util::addScript(Application::APP_ID, 'main');
		Util::addStyle(Application::APP_ID, 'main');
		return new TemplateResponse(Application::APP_ID, 'main');
	}
}

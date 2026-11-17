<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use DateTimeZone;
use OCA\CalenAI\AppInfo\Application;
use OCA\CalenAI\Exception\ServiceException;
use OCP\Config\IUserConfig;
use OCP\IConfig;

/**
 * 利用者ごとのタイムゾーン。決め方は {@see TimezoneResolver} を参照。
 */
class UserTimezone {
	private const CONFIG_TIMEZONE = 'timezone';

	public function __construct(
		private IUserConfig $userConfig,
		private IConfig $config,
	) {
	}

	/**
	 * @param string|null $browser ブラウザが報告したタイムゾーン
	 */
	public function get(string $userId, ?string $browser): DateTimeZone {
		return $this->resolve($userId, $browser)['timezone'];
	}

	/**
	 * 画面に見せる情報。
	 *
	 * @return array{effective: string, setting: string, source: string, browser: string, browserMasked: bool}
	 */
	public function describe(string $userId, ?string $browser): array {
		$resolved = $this->resolve($userId, $browser);
		return [
			'effective' => $resolved['timezone']->getName(),
			'setting' => $this->getSetting($userId),
			'source' => $resolved['source'],
			'browser' => TimezoneResolver::isValid($browser) ? (string)$browser : '',
			'browserMasked' => $resolved['browserMasked'],
		];
	}

	/**
	 * @param string $timezone 空文字なら「自動」に戻す
	 * @throws ServiceException
	 */
	public function setSetting(string $userId, string $timezone): void {
		if ($timezone === '') {
			$this->userConfig->deleteUserConfig($userId, Application::APP_ID, self::CONFIG_TIMEZONE);
			return;
		}
		if (!TimezoneResolver::isValid($timezone)) {
			throw ServiceException::invalid('タイムゾーンの指定が正しくありません。');
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, self::CONFIG_TIMEZONE, $timezone);
	}

	private function getSetting(string $userId): string {
		$setting = $this->userConfig->getValueString($userId, Application::APP_ID, self::CONFIG_TIMEZONE, '');
		return TimezoneResolver::isValid($setting) ? $setting : '';
	}

	/**
	 * @return array{timezone: DateTimeZone, source: string, browserMasked: bool}
	 */
	private function resolve(string $userId, ?string $browser): array {
		return TimezoneResolver::resolve(
			$this->getSetting($userId),
			$browser,
			$this->userConfig->getValueString($userId, 'core', 'timezone', ''),
			$this->config->getSystemValueString('default_timezone', ''),
		);
	}
}

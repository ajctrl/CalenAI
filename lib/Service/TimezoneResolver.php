<?php

declare(strict_types=1);

namespace OCA\CalenAI\Service;

use DateTimeZone;

/**
 * 予定の日時を解釈するタイムゾーンを決める。Nextcloud に依存しない純粋なロジック。
 *
 * ブラウザが報告するタイムゾーンは、プライバシー保護機能（Firefox の指紋採取防止など）で
 * 実際と違う値（UTC や Atlantic/Reykjavik）に置き換えられることがある。
 * そのまま信じると「明日」の日付や時刻がずれるので、次の順で決める:
 *
 *   1. 利用者がこのアプリで明示的に選んだタイムゾーン
 *   2. ブラウザの報告（置き換えられた疑いがない値のとき）
 *   3. Nextcloud のユーザー設定（同じく疑いがない値のとき）
 *   4. Nextcloud の既定のタイムゾーン（config.php の default_timezone）
 *   5. どれも UTC 相当なら、ブラウザの報告をそのまま使う（本当に UTC の地域の利用者）
 */
class TimezoneResolver {
	/** プライバシー保護機能が本当のタイムゾーンの代わりに報告する値 */
	private const MASKED = ['UTC', 'Etc/UTC', 'Etc/GMT', 'GMT', 'Atlantic/Reykjavik'];

	/**
	 * @return array{timezone: DateTimeZone, source: string, browserMasked: bool}
	 *   source は setting | browser | nextcloud | default のどれか
	 */
	public static function resolve(?string $setting, ?string $browser, ?string $nextcloudUser, ?string $systemDefault): array {
		$browserMasked = self::isValid($browser) && self::isMasked($browser);

		if (self::isValid($setting)) {
			return self::result($setting, 'setting', $browserMasked);
		}
		if (self::isValid($browser) && !$browserMasked) {
			return self::result($browser, 'browser', false);
		}
		if (self::isValid($nextcloudUser) && !self::isMasked($nextcloudUser)) {
			return self::result($nextcloudUser, 'nextcloud', $browserMasked);
		}
		if (self::isValid($systemDefault) && !self::isMasked($systemDefault)) {
			return self::result($systemDefault, 'default', $browserMasked);
		}
		foreach (['browser' => $browser, 'nextcloud' => $nextcloudUser, 'default' => $systemDefault] as $source => $candidate) {
			if (self::isValid($candidate)) {
				return self::result($candidate, $source, $browserMasked);
			}
		}
		return self::result('UTC', 'default', $browserMasked);
	}

	public static function isValid(?string $timezone): bool {
		if ($timezone === null || $timezone === '' || !in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
			return false;
		}
		try {
			new DateTimeZone($timezone);
			return true;
		} catch (\Exception) {
			// OS の tzdata によっては、一覧に補助ファイルの名前も含まれる。
			return false;
		}
	}

	public static function isMasked(string $timezone): bool {
		return in_array($timezone, self::MASKED, true);
	}

	/**
	 * @return array{timezone: DateTimeZone, source: string, browserMasked: bool}
	 */
	private static function result(string $timezone, string $source, bool $browserMasked): array {
		return ['timezone' => new DateTimeZone($timezone), 'source' => $source, 'browserMasked' => $browserMasked];
	}
}

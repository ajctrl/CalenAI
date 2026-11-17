<?php

declare(strict_types=1);

namespace OCA\CalenAI\Tests\Unit;

use OCA\CalenAI\Service\TimezoneResolver;
use PHPUnit\Framework\TestCase;

class TimezoneResolverTest extends TestCase {
	private static function pick(?string $setting, ?string $browser, ?string $nextcloud, ?string $default): string {
		return TimezoneResolver::resolve($setting, $browser, $nextcloud, $default)['timezone']->getName();
	}

	public function testOrdinaryBrowserTimezoneIsUsed(): void {
		$result = TimezoneResolver::resolve('', 'Asia/Tokyo', 'Europe/Berlin', 'UTC');
		$this->assertSame('Asia/Tokyo', $result['timezone']->getName());
		$this->assertSame('browser', $result['source']);
		$this->assertFalse($result['browserMasked']);
	}

	public function testExplicitSettingAlwaysWins(): void {
		$result = TimezoneResolver::resolve('Asia/Tokyo', 'Atlantic/Reykjavik', 'Atlantic/Reykjavik', 'UTC');
		$this->assertSame('Asia/Tokyo', $result['timezone']->getName());
		$this->assertSame('setting', $result['source']);
		$this->assertTrue($result['browserMasked']);

		$this->assertSame('America/New_York', self::pick('America/New_York', 'Asia/Tokyo', 'Asia/Tokyo', 'Asia/Tokyo'));
	}

	public function testMaskedBrowserTimezoneFallsBackToNextcloudUserSetting(): void {
		// Firefox の指紋採取防止は Atlantic/Reykjavik（以前は UTC）を報告する
		foreach (['Atlantic/Reykjavik', 'UTC', 'Etc/UTC', 'GMT'] as $masked) {
			$result = TimezoneResolver::resolve('', $masked, 'Asia/Tokyo', 'UTC');
			$this->assertSame('Asia/Tokyo', $result['timezone']->getName(), $masked);
			$this->assertSame('nextcloud', $result['source']);
			$this->assertTrue($result['browserMasked']);
		}
	}

	public function testMaskedEverywhereFallsBackToSystemDefault(): void {
		// ログイン時にブラウザの値が Nextcloud のユーザー設定にも入るので、両方とも偽の値になりうる
		$result = TimezoneResolver::resolve('', 'Atlantic/Reykjavik', 'Atlantic/Reykjavik', 'Asia/Tokyo');
		$this->assertSame('Asia/Tokyo', $result['timezone']->getName());
		$this->assertSame('default', $result['source']);
		$this->assertTrue($result['browserMasked']);
	}

	public function testGenuineUtcUserStillWorksWhenNothingBetterIsKnown(): void {
		$result = TimezoneResolver::resolve('', 'Atlantic/Reykjavik', 'Atlantic/Reykjavik', '');
		$this->assertSame('Atlantic/Reykjavik', $result['timezone']->getName());
		$this->assertSame('browser', $result['source']);
		$this->assertTrue($result['browserMasked']);

		$this->assertSame('UTC', self::pick('', 'UTC', '', 'UTC'));
	}

	public function testInvalidValuesAreIgnored(): void {
		$this->assertSame('Asia/Tokyo', self::pick('Mars/Olympus', 'Asia/Tokyo', '', ''));
		$this->assertSame('Europe/Berlin', self::pick('', "Asia/Tokyo\nX", 'Europe/Berlin', ''));
		$this->assertSame('UTC', self::pick(null, null, null, null));
		$this->assertSame('UTC', self::pick('', '../../etc/passwd', 'nope', 'nope'));
	}

	public function testValidity(): void {
		$this->assertTrue(TimezoneResolver::isValid('Asia/Tokyo'));
		$this->assertTrue(TimezoneResolver::isValid('UTC'));
		$this->assertFalse(TimezoneResolver::isValid(''));
		$this->assertFalse(TimezoneResolver::isValid(null));
		$this->assertFalse(TimezoneResolver::isValid('+09:00'));
		$this->assertFalse(TimezoneResolver::isValid('tzdata.zi'));
		$this->assertSame('default', TimezoneResolver::resolve('', null, '', 'UTC')['source']);
	}
}

<?php

declare(strict_types=1);

namespace OCA\CalenAI\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\CalenAI\Exception\ServiceException;
use OCA\CalenAI\Service\AiClient;
use OCA\CalenAI\Service\AiConfig;
use OCA\CalenAI\Service\MessageInterpreter;
use PHPUnit\Framework\TestCase;

class MessageInterpreterTest extends TestCase {
	private const CANDIDATES = [
		['id' => 12, 'title' => '歯医者', 'start' => '2026-11-17T10:00', 'end' => '2026-11-17T11:00', 'allDay' => false],
		['id' => 7, 'title' => '出張', 'start' => '2026-11-20', 'end' => '2026-11-21', 'allDay' => true],
	];

	private static function raw(array $overrides = [], array $event = []): array {
		return $overrides + [
			'action' => 'create',
			'targetRef' => null,
			'calendarRef' => null,
			'event' => $event + ['title' => null, 'start' => null, 'end' => null, 'allDay' => null, 'location' => null, 'description' => null],
			'changedFields' => [],
			'reply' => '',
		];
	}

	// ---- AI の出力の検証

	public function testCreateIsNormalized(): void {
		$result = MessageInterpreter::validate(
			self::raw(['reply' => '明日10時に歯医者を登録しました'], ['title' => '歯医者', 'start' => '2026-11-17T10:00', 'allDay' => false]),
			[],
		);
		$this->assertSame('create', $result['action']);
		$this->assertSame('2026-11-17T11:00', $result['event']['end']);
		$this->assertSame('明日10時に歯医者を登録しました', $result['reply']);
	}

	public function testCreateWithoutStartBecomesUnclearInsteadOfSaving(): void {
		$result = MessageInterpreter::validate(self::raw([], ['title' => '歯医者']), []);
		$this->assertSame('unclear', $result['action']);
		$this->assertNotSame('', $result['reply']);
	}

	public function testCreateWithEndBeforeStartBecomesUnclear(): void {
		$result = MessageInterpreter::validate(
			self::raw([], ['title' => 'x', 'start' => '2026-11-17T10:00', 'end' => '2026-11-17T09:00', 'allDay' => false]),
			[],
		);
		$this->assertSame('unclear', $result['action']);
	}

	public function testUpdateTargetsTheReferencedHistoryEntry(): void {
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 1, 'changedFields' => ['start']], ['start' => '2026-11-17T11:00']),
			self::CANDIDATES,
		);
		$this->assertSame('update', $result['action']);
		$this->assertSame(12, $result['target']['id']);
		$this->assertSame(['start'], $result['changedFields']);
	}

	public function testUpdateWithRefOutsideOwnHistoryIsAmbiguous(): void {
		foreach ([null, 0, 3, -1, '1', 99] as $ref) {
			$result = MessageInterpreter::validate(
				self::raw(['action' => 'update', 'targetRef' => $ref, 'changedFields' => ['start']], ['start' => '2026-11-17T11:00']),
				self::CANDIDATES,
			);
			$this->assertSame('ambiguous', $result['action'], 'targetRef=' . var_export($ref, true));
			$this->assertSame('update', $result['intent']);
			$this->assertCount(2, $result['candidates']);
		}
	}

	public function testUpdateWithoutAnyHistoryIsUnclear(): void {
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 1, 'changedFields' => ['start']], ['start' => '2026-11-17T11:00']),
			[],
		);
		$this->assertSame('unclear', $result['action']);
	}

	public function testUpdateDropsChangedFieldsWithoutValues(): void {
		// start は値が無いので捨てる。location は「消す」指示として残す。
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 2, 'changedFields' => ['start', 'location', 'bogus']], ['location' => '']),
			self::CANDIDATES,
		);
		$this->assertSame('update', $result['action']);
		$this->assertSame(['location'], $result['changedFields']);
	}

	public function testNullNeverClearsLocationOrDescription(): void {
		// 「場所は会議室B」に対して、モデルが changedFields に description まで入れ、値は null のまま返した場合。
		// null は「変えない」なので、説明を消してはいけない。
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 1, 'changedFields' => ['location', 'description']], ['location' => '会議室B', 'description' => null]),
			self::CANDIDATES,
		);
		$this->assertSame('update', $result['action']);
		$this->assertSame(['location'], $result['changedFields']);

		// 消す指示（空文字）は残す
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 1, 'changedFields' => ['location', 'description']], ['location' => null, 'description' => '']),
			self::CANDIDATES,
		);
		$this->assertSame(['description'], $result['changedFields']);
	}

	public function testUpdateWithNothingToChangeIsUnclear(): void {
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 1, 'changedFields' => ['start']]),
			self::CANDIDATES,
		);
		$this->assertSame('unclear', $result['action']);
	}

	public function testDeleteNeverCarriesAnythingButTheTarget(): void {
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'delete', 'targetRef' => 2, 'reply' => '削除しました']),
			self::CANDIDATES,
		);
		$this->assertSame('delete', $result['action']);
		$this->assertSame(7, $result['target']['id']);
		// AI が「削除しました」と言っても、確認の文に置き換える
		$this->assertSame('この予定を削除しますか？', $result['reply']);
	}

	public function testDeleteWithUnknownTargetIsAmbiguous(): void {
		$result = MessageInterpreter::validate(self::raw(['action' => 'delete', 'targetRef' => null]), self::CANDIDATES);
		$this->assertSame('ambiguous', $result['action']);
		$this->assertSame('delete', $result['intent']);
	}

	public function testUnknownActionIsUnclear(): void {
		foreach (['drop_table', null, 42] as $action) {
			$result = MessageInterpreter::validate(self::raw(['action' => $action]), self::CANDIDATES);
			$this->assertSame('unclear', $result['action']);
		}
	}

	public function testGarbageOutputIsUnclear(): void {
		$result = MessageInterpreter::validate(['hello' => 'world'], self::CANDIDATES);
		$this->assertSame('unclear', $result['action']);
	}

	// ---- カレンダーの自動振り分け

	private const CALENDARS = [
		['id' => 4, 'name' => '個人', 'hint' => '病院、買い物', 'isDefault' => true],
		['id' => 9, 'name' => '仕事', 'hint' => '会議、打合せ、出張', 'isDefault' => false],
	];

	public function testCreateUsesTheCalendarChosenFromTheList(): void {
		$result = MessageInterpreter::validate(
			self::raw(['calendarRef' => 2], ['title' => '定例会議', 'start' => '2026-11-17T10:00', 'allDay' => false]),
			[],
			self::CALENDARS,
		);
		$this->assertSame('create', $result['action']);
		$this->assertSame(9, $result['calendarId']);
	}

	public function testCalendarRefOutsideTheListMeansNoChoice(): void {
		foreach ([null, 0, 3, -1, '2', 99] as $ref) {
			$result = MessageInterpreter::validate(
				self::raw(['calendarRef' => $ref], ['title' => 'x', 'start' => '2026-11-17T10:00', 'allDay' => false]),
				[],
				self::CALENDARS,
			);
			$this->assertSame('create', $result['action']);
			$this->assertNull($result['calendarId'], 'calendarRef=' . var_export($ref, true));
		}
	}

	public function testCalendarRefIsIgnoredWhenNoCalendarsWereShown(): void {
		// 自動振り分けを使わない利用者: AI が番号を返してきても使わない
		$result = MessageInterpreter::validate(
			self::raw(['calendarRef' => 1], ['title' => 'x', 'start' => '2026-11-17T10:00', 'allDay' => false]),
			[],
			[],
		);
		$this->assertNull($result['calendarId']);
	}

	public function testUpdateCanBeAPureMoveToAnotherCalendar(): void {
		$candidates = [['id' => 12, 'title' => '歯医者', 'start' => '2026-11-17T10:00', 'end' => '2026-11-17T11:00', 'allDay' => false, 'calendarId' => 4]];
		$result = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 1, 'calendarRef' => 2]),
			$candidates,
			self::CALENDARS,
		);
		$this->assertSame('update', $result['action']);
		$this->assertSame([], $result['changedFields']);
		$this->assertSame(9, $result['calendarId']);
	}

	public function testUpdateToTheSameCalendarIsNotAMove(): void {
		$candidates = [['id' => 12, 'title' => '歯医者', 'start' => '2026-11-17T10:00', 'end' => '2026-11-17T11:00', 'allDay' => false, 'calendarId' => 4]];
		$moveOnly = MessageInterpreter::validate(self::raw(['action' => 'update', 'targetRef' => 1, 'calendarRef' => 1]), $candidates, self::CALENDARS);
		$this->assertSame('unclear', $moveOnly['action']);

		$withChange = MessageInterpreter::validate(
			self::raw(['action' => 'update', 'targetRef' => 1, 'calendarRef' => 1, 'changedFields' => ['start']], ['start' => '2026-11-17T11:00']),
			$candidates,
			self::CALENDARS,
		);
		$this->assertSame('update', $withChange['action']);
		$this->assertNull($withChange['calendarId']);
	}

	public function testUserPromptListsCalendarsWithHintsOnlyWhenGiven(): void {
		$now = new DateTimeImmutable('2026-11-16T05:05:00Z');
		$tz = new DateTimeZone('Asia/Tokyo');
		$with = MessageInterpreter::userPrompt('明日10時から会議', [], $now, $tz, self::CALENDARS);
		$this->assertStringContainsString("カレンダー:\n1. 個人 | 入れるもの: 病院、買い物 | （どれにも当てはまらないときの既定）\n2. 仕事 | 入れるもの: 会議、打合せ、出張", $with);
		// 内部の ID は送らない
		$this->assertStringNotContainsString('9', str_replace(['2026', '09'], '', $with));

		$without = MessageInterpreter::userPrompt('明日10時から会議', [], $now, $tz, []);
		$this->assertStringNotContainsString('カレンダー:', $without);
	}

	public function testInterpretPassesCalendarsToTheModelAndReturnsTheChoice(): void {
		$prompts = [];
		$interpreter = $this->interpreter(AiConfig::CANDIDATES_ALWAYS, [
			self::raw(['calendarRef' => 2], ['title' => '定例会議', 'start' => '2026-11-17T10:00', 'allDay' => false]),
		], $prompts);
		$result = $interpreter->interpret('明日10時から定例会議', fn () => [], new DateTimeImmutable(), new DateTimeZone('Asia/Tokyo'), self::CALENDARS);
		$this->assertSame(9, $result['calendarId']);
		$this->assertStringContainsString('2. 仕事', $prompts[0]);
	}

	// ---- 入力の検証

	public function testEmptyInputIsRejected(): void {
		$this->expectException(ServiceException::class);
		MessageInterpreter::cleanInput("  \n ");
	}

	public function testInputLongerThanLimitIsRejected(): void {
		$this->assertSame(1000, mb_strlen(MessageInterpreter::cleanInput(str_repeat('あ', 1000))));
		$this->expectException(ServiceException::class);
		MessageInterpreter::cleanInput(str_repeat('あ', 1001));
	}

	// ---- AI に渡す内容

	public function testHistoryLengthLimitAppliesToPreviousTurnsOnly(): void {
		$history = [
			['role' => 'user', 'content' => str_repeat('明', 6000)],
			['role' => 'assistant', 'content' => str_repeat('📅', 6000)],
		];
		$now = new DateTimeImmutable();
		$tz = new DateTimeZone('Asia/Tokyo');
		$prompts = [];
		$interpreter = $this->interpreter(AiConfig::CANDIDATES_ALWAYS, [self::raw(['action' => 'unclear'])], $prompts);
		$interpreter->interpret('次の予定', fn () => [], $now, $tz, [], $history);

		$history[1]['content'] .= '。';
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('「会話をリセット」');
		$interpreter->interpret('次の予定', fn () => self::fail('上限超過時は候補を取得しない'), $now, $tz, [], $history);
	}

	public function testUserPromptContainsDateWeekdayTimezoneAndNumberedCandidates(): void {
		$now = new DateTimeImmutable('2026-11-16T05:05:00Z');
		$prompt = MessageInterpreter::userPrompt('いや11時だった', self::CANDIDATES, $now, new DateTimeZone('Asia/Tokyo'));
		$this->assertStringContainsString('現在日時: 2026-11-16（月） 14:05', $prompt);
		$this->assertStringContainsString('タイムゾーン: Asia/Tokyo', $prompt);
		$this->assertStringContainsString('1. 歯医者 | 2026-11-17T10:00 〜 2026-11-17T11:00', $prompt);
		$this->assertStringContainsString('2. 出張 | 2026-11-20 〜 2026-11-21（終日）', $prompt);
		$this->assertStringContainsString("<<<\nいや11時だった\n>>>", $prompt);
	}

	public function testUserPromptDoesNotLeakIdsLocationsOrDescriptions(): void {
		$candidates = [['id' => 987654, 'title' => '面談', 'start' => '2026-11-17T10:00', 'end' => '2026-11-17T11:00', 'allDay' => false, 'location' => '秘密の場所', 'description' => '内緒のメモ', 'calendarName' => '個人']];
		$prompt = MessageInterpreter::userPrompt('x', $candidates, new DateTimeImmutable('2026-11-16T00:00:00Z'), new DateTimeZone('UTC'));
		$this->assertStringNotContainsString('987654', $prompt);
		$this->assertStringNotContainsString('秘密の場所', $prompt);
		$this->assertStringNotContainsString('内緒のメモ', $prompt);
	}

	public function testSchemaIsStrict(): void {
		$schema = MessageInterpreter::schema();
		$this->assertFalse($schema['additionalProperties']);
		$this->assertSame(array_keys($schema['properties']), $schema['required']);
		$this->assertFalse($schema['properties']['event']['additionalProperties']);
		$this->assertSame(array_keys($schema['properties']['event']['properties']), $schema['properties']['event']['required']);
	}

	// ---- 「最近の予定」を送るタイミング

	private function interpreter(string $mode, array $responses, array &$prompts): MessageInterpreter {
		$config = $this->createStub(AiConfig::class);
		$config->method('getCandidateMode')->willReturn($mode);

		$client = $this->createMock(AiClient::class);
		$client->expects($this->exactly(count($responses)))
			->method('completeJson')
			->willReturnCallback(function (string $system, string $user) use (&$responses, &$prompts) {
				$prompts[] = $user;
				return array_shift($responses);
			});
		return new MessageInterpreter($client, $config);
	}

	public function testAlwaysModeSendsCandidatesInASingleCall(): void {
		$prompts = [];
		$interpreter = $this->interpreter(AiConfig::CANDIDATES_ALWAYS, [
			self::raw(['action' => 'update', 'targetRef' => 1, 'changedFields' => ['start']], ['start' => '2026-11-17T11:00']),
		], $prompts);

		$result = $interpreter->interpret('いや11時だった', fn () => self::CANDIDATES, new DateTimeImmutable(), new DateTimeZone('Asia/Tokyo'));
		$this->assertSame('update', $result['action']);
		$this->assertCount(1, $prompts);
		$this->assertStringContainsString('1. 歯医者', $prompts[0]);
	}

	public function testOnDemandModeDoesNotSendCandidatesWhenCreating(): void {
		$prompts = [];
		$loaded = false;
		$interpreter = $this->interpreter(AiConfig::CANDIDATES_ON_DEMAND, [
			self::raw([], ['title' => '歯医者', 'start' => '2026-11-17T10:00', 'allDay' => false]),
		], $prompts);

		$result = $interpreter->interpret('明日10時から歯医者', function () use (&$loaded) {
			$loaded = true;
			return self::CANDIDATES;
		}, new DateTimeImmutable(), new DateTimeZone('Asia/Tokyo'));

		$this->assertSame('create', $result['action']);
		$this->assertFalse($loaded, '新規作成では履歴を読まない');
		$this->assertCount(1, $prompts);
		$this->assertStringNotContainsString('歯医者 |', $prompts[0]);
		$this->assertStringNotContainsString('出張', $prompts[0]);
	}

	public function testOnDemandWithholdsCardHistoryUntilTheTargetLookup(): void {
		$chat = ['role' => 'user', 'content' => '予定を変更したい'];
		$card = ['source' => 'card', 'role' => 'user', 'content' => '非公開の予定'];
		$legacyCard = ['role' => 'user', 'content' => '[カード操作結果] 削除をキャンセルした予定：秘密'];
		$saved = ['role' => 'assistant', 'content' => '[updated] 秘密の面談を変更しました', 'savedEvent' => "\n保存した予定: 秘密の面談 | 2026-11-22T10:00"];
		$legacySaved = ['role' => 'assistant', 'content' => "[created] 登録しました\n保存した予定: 過去の予定"];
		$question = ['role' => 'assistant', 'source' => 'candidates', 'content' => '[unclear] 秘密の面談は何時ですか？'];
		$history = [$card, $chat, $legacyCard, $saved, $legacySaved, $question];
		$seen = [];
		$config = $this->createStub(AiConfig::class);
		$config->method('getCandidateMode')->willReturn(AiConfig::CANDIDATES_ON_DEMAND);
		$client = $this->createMock(AiClient::class);
		$client->expects($this->exactly(2))->method('completeJson')->willReturnCallback(
			function ($system, $user, $schema, $name, $turns) use (&$seen) {
				$seen[] = $turns;
				return self::raw(['action' => 'delete', 'targetRef' => 1]);
			}
		);
		$interpreter = new MessageInterpreter($client, $config);
		$interpreter->interpret('それを消して', fn () => self::CANDIDATES, new DateTimeImmutable(), new DateTimeZone('UTC'), [], $history);
		$this->assertSame([$chat, ['role' => 'assistant', 'content' => '[updated]'], ['role' => 'assistant', 'content' => '[created]'], ['role' => 'assistant', 'content' => '[unclear]']], $seen[0]);
		$history[3]['content'] .= $saved['savedEvent'];
		$this->assertSame($history, $seen[1]);
	}

	public function testOnDemandConflictingActionsRequireClarification(): void {
		$prompts = [];
		$interpreter = $this->interpreter(AiConfig::CANDIDATES_ON_DEMAND, [
			self::raw(['action' => 'delete']),
			self::raw(['action' => 'create'], ['start' => '2026-11-17T10:00']),
		], $prompts);
		$result = $interpreter->interpret('歯医者を消して', fn () => self::CANDIDATES, new DateTimeImmutable(), new DateTimeZone('Asia/Tokyo'));
		$this->assertSame('unclear', $result['action']);
	}

	public function testOnDemandModeSendsCandidatesOnlyInSecondCall(): void {
		$prompts = [];
		$interpreter = $this->interpreter(AiConfig::CANDIDATES_ON_DEMAND, [
			self::raw(['action' => 'update']),
			self::raw(['action' => 'update', 'targetRef' => 1, 'changedFields' => ['start']], ['start' => '2026-11-17T11:00']),
		], $prompts);

		$result = $interpreter->interpret('いや11時だった', fn () => self::CANDIDATES, new DateTimeImmutable(), new DateTimeZone('Asia/Tokyo'));
		$this->assertSame('update', $result['action']);
		$this->assertSame(12, $result['target']['id']);
		$this->assertCount(2, $prompts);
		$this->assertStringNotContainsString('1. 歯医者', $prompts[0]);
		$this->assertStringContainsString('1. 歯医者', $prompts[1]);
	}

	public function testOnDemandModeWithEmptyHistorySkipsSecondCall(): void {
		$prompts = [];
		$interpreter = $this->interpreter(AiConfig::CANDIDATES_ON_DEMAND, [
			self::raw(['action' => 'delete']),
		], $prompts);

		$result = $interpreter->interpret('さっきの消して', fn () => [], new DateTimeImmutable(), new DateTimeZone('Asia/Tokyo'));
		$this->assertSame('unclear', $result['action']);
		$this->assertCount(1, $prompts);
	}
}

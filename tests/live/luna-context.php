<?php

declare(strict_types=1);

use OCA\CalenAI\Service\MessageInterpreter;

require __DIR__ . '/../../vendor/autoload.php';

// Run manually with OPENAI_API_KEY in the environment. Uses only fictional events.
$key = getenv('OPENAI_API_KEY');
if (!$key) {
	fwrite(STDERR, "OPENAI_API_KEY is required. No requests sent.\n");
	exit(2);
}

$tz = new DateTimeZone('Asia/Tokyo');
$now = new DateTimeImmutable('2026-11-22 00:05', $tz);
$title = 'アップグレード警戒';
$event = ['id' => 2, 'title' => $title, 'start' => '2026-11-22T19:00', 'end' => '2026-11-22T20:00', 'allDay' => false];
$other = ['id' => 1, 'title' => '読書会', 'start' => '2026-11-23T10:00', 'end' => '2026-11-23T11:00', 'allDay' => false];
$expectedStart = '2026-11-22T21:00';
$passed = true;

foreach (['update', 'create'] as $expected) {
	$saved = $expected === 'update';
	$candidates = $saved ? [$other, $event] : [$other];
	$reply = $saved
		? "[created] 追加しました。\n保存した予定: $title | 2026-11-22T19:00 〜 2026-11-22T20:00"
		: '[unclear] 何時からの予定ですか？';
	$body = [
		'model' => 'gpt-6-luna',
		'messages' => [
			['role' => 'system', 'content' => MessageInterpreter::systemPrompt()],
			['role' => 'user', 'content' => "[2026-11-21 23:55:00 Asia/Tokyo]\n明日の夜 $title"],
			['role' => 'assistant', 'content' => $reply],
			['role' => 'user', 'content' => MessageInterpreter::userPrompt('それ、21時にして', $candidates, $now, $tz)],
		],
		'response_format' => ['type' => 'json_schema', 'json_schema' => [
			'name' => 'calendar_intent', 'strict' => true, 'schema' => MessageInterpreter::schema(),
		]],
	];
	$context = stream_context_create(['http' => [
		'method' => 'POST', 'timeout' => 90, 'follow_location' => 0,
		'header' => "Content-Type: application/json\r\nAuthorization: Bearer $key",
		'content' => json_encode($body, JSON_THROW_ON_ERROR),
	]]);
	$response = file_get_contents('https://api.openai.com/v1/chat/completions', false, $context);
	if ($response === false) {
		exit(2);
	}
	$data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
	$raw = json_decode($data['choices'][0]['message']['content'] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
	$result = MessageInterpreter::validate(is_array($raw) ? $raw : [], $candidates, [], $tz);
	$ok = $result['action'] === $expected && ($saved
		? $result['target']['id'] === $event['id'] && $result['changedFields'] === ['start'] && $result['patch']['start'] === $expectedStart
		: $result['event']['title'] === $title && $result['event']['start'] === $expectedStart);
	printf("%s %s: %s\n", $ok ? 'PASS' : 'FAIL', $expected, json_encode($result, JSON_UNESCAPED_UNICODE));
	$passed = $passed && $ok;
}
exit($passed ? 0 : 1);

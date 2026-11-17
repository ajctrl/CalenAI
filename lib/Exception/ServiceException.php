<?php

declare(strict_types=1);

namespace OCA\CalenAI\Exception;

/**
 * 利用者にそのまま見せてよいメッセージと HTTP ステータスを持つ例外。
 * メッセージに入力文や予定の内容を入れないこと（ログに残る可能性があるため）。
 */
class ServiceException extends \Exception {
	/**
	 * @param array<string, mixed> $data レスポンスに追加する情報
	 */
	public function __construct(
		string $message,
		private int $status = 400,
		private array $data = [],
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getStatus(): int {
		return $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getData(): array {
		return $this->data;
	}

	public static function notFound(string $message = 'この予定は見つかりません。カレンダーから削除された可能性があります。'): self {
		return new self($message, 404);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function conflict(string $message, array $data = []): self {
		return new self($message, 409, $data);
	}

	public static function invalid(string $message): self {
		return new self($message, 422);
	}

	public static function notConfigured(string $message = 'AIの設定がまだ済んでいません。管理者に連絡してください。'): self {
		return new self($message, 503);
	}

	public static function ai(string $message, ?\Throwable $previous = null): self {
		return new self($message, 502, [], $previous);
	}
}

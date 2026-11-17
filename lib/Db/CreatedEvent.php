<?php

declare(strict_types=1);

namespace OCA\CalenAI\Db;

use DateTimeImmutable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCalendarId()
 * @method void setCalendarId(int $calendarId)
 * @method int|null getObjectId()
 * @method void setObjectId(?int $objectId)
 * @method int getRevision()
 * @method void setRevision(int $revision)
 * @method string getObjectUri()
 * @method void setObjectUri(string $objectUri)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getEtag()
 * @method void setEtag(string $etag)
 * @method string|null getPrevIcs()
 * @method void setPrevIcs(?string $prevIcs)
 * @method int|null getPrevCalendarId()
 * @method void setPrevCalendarId(?int $prevCalendarId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAtUsec()
 * @method void setUpdatedAtUsec(int $updatedAtUsec)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class CreatedEvent extends Entity {
	protected string $userId = '';
	protected int $calendarId = 0;
	protected ?int $objectId = null;
	protected int $revision = 0;
	protected string $objectUri = '';
	protected string $uid = '';
	protected string $etag = '';
	protected ?string $prevIcs = null;
	protected ?int $prevCalendarId = null;
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected int $updatedAtUsec = 0;

	public function __construct() {
		$this->addType('objectId', Types::BIGINT);
		$this->addType('revision', Types::BIGINT);
		$this->addType('calendarId', Types::BIGINT);
		$this->addType('prevCalendarId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('updatedAtUsec', Types::INTEGER);
	}

	public function markUpdated(DateTimeImmutable $now): void {
		$this->setUpdatedAt($now->getTimestamp());
		$this->setUpdatedAtUsec((int)$now->format('u'));
	}
}

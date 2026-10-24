<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Declares the trait indexes itself - by columns and by name - so they must not be doubled.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_comment_created', columns: ['created_at'])]
#[ORM\Index(fields: ['owner'])]
#[ORM\UniqueConstraint(name: 'uniq_code', fields: ['code', 'owner'])]
class Comment extends Base
{
	use Owned;
}

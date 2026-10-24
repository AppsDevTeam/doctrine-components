<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Nested trait plus an association index and a named unique constraint.
 */
#[ORM\Index(fields: ['owner'])]
#[ORM\UniqueConstraint(name: 'uniq_code', fields: ['code'])]
trait Owned
{
	use Timestamped;

	#[ORM\ManyToOne(targetEntity: Owner::class)]
	protected ?Owner $owner = null;

	#[ORM\Column]
	protected string $code;
}

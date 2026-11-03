<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Removal;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Soft-deleteable entita - soft delete obstarává FakeSoftDeleteListener (jako Gedmo SoftDeleteable).
 */
#[ORM\Entity]
class Attachment
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\Column(nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;
}

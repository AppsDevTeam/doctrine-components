<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Index(columns: ['external_id'], flags: [], options: [])]
trait Identified
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	protected ?int $id = null;

	#[ORM\Column]
	protected string $externalId;
}

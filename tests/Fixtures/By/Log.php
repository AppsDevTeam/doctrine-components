<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\By;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Log
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\Column]
	public string $url = '';

	#[ORM\Column(nullable: true)]
	public ?int $code = null;

	#[ORM\Column(type: 'smallint', options: ['unsigned' => true])]
	public int $priority = 0;

	#[ORM\ManyToOne(targetEntity: Device::class)]
	public ?Device $device = null;
}

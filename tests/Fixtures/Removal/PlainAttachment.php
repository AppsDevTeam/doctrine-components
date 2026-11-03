<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Removal;

use Doctrine\ORM\Mapping as ORM;

/**
 * Entita bez soft delete - řádek může skrýt HiddenFilter (napodobuje třeba filtr podle tenantu).
 */
#[ORM\Entity]
class PlainAttachment
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\Column]
	public bool $hidden = false;
}

<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Removal;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Holder
{
	#[ORM\Id]
	#[ORM\Column]
	#[ORM\GeneratedValue]
	public ?int $id = null;

	#[ORM\OneToOne(targetEntity: Attachment::class, orphanRemoval: true)]
	public ?Attachment $attachment = null;

	#[ORM\OneToOne(targetEntity: PlainAttachment::class, orphanRemoval: true)]
	public ?PlainAttachment $plainAttachment = null;
}

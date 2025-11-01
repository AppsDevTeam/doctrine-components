<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Entities;

interface Entity
{
	public function getId();
	public function isNew(): bool;
}

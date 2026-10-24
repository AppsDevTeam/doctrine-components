<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\MappedSuperclass]
abstract class Base
{
	use Identified;
}

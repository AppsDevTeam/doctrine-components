<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Gets every index from its traits.
 */
#[ORM\Entity]
class Article extends Base
{
	use Owned;
}

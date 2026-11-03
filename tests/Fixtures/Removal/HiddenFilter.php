<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Removal;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Filtr, který není soft-delete (napodobuje třeba filtr podle tenantu) - skryje řádky s hidden = 1.
 */
class HiddenFilter extends SQLFilter
{
	public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
	{
		if ($targetEntity->getName() !== PlainAttachment::class) {
			return '';
		}

		return $targetTableAlias . '.hidden = 0';
	}
}

<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Removal;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Napodobuje Gedmo SoftDeleteableFilter - soft-smazané řádky skryje i při načítání proxy.
 */
class SoftDeleteFilter extends SQLFilter
{
	public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
	{
		if ($targetEntity->getName() !== Attachment::class) {
			return '';
		}

		return $targetTableAlias . '.deleted_at IS NULL';
	}
}

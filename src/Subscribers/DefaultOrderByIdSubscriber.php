<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Subscribers;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Doctrine event subscriber that ensures all collection associations
 * (OneToMany, ManyToMany) have a stable default ordering by "id" (ASC),
 * unless an explicit order for "id" is already defined on the association.
 */
final class DefaultOrderByIdSubscriber implements EventSubscriber
{
	public function getSubscribedEvents(): array
	{
		return [
			Events::loadClassMetadata,
		];
	}

	public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
	{
		$metadata = $args->getClassMetadata();

		foreach ($metadata->associationMappings as $fieldName => $mapping) {
			if (!in_array($mapping['type'], [ClassMetadata::ONE_TO_MANY, ClassMetadata::MANY_TO_MANY], true)) {
				continue;
			}

			// Add "ORDER BY id ASC", except there is "ORDER BY id ASC/DESC"
			$addOrderById = true;
			$orderBy = $mapping['orderBy'] ?? [];
			foreach ($orderBy as $sort => $order) {
				if ($sort === 'id') {
					$addOrderById = false;
					break;
				}
			}

			if ($addOrderById) {
				$orderBy['id'] = 'ASC';
				$metadata->associationMappings[$fieldName]['orderBy'] = $orderBy;
			}
		}
	}
}

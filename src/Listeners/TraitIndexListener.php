<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\UniqueConstraint;
use ReflectionClass;

/**
 * Doplní entitě indexy a unikátní omezení deklarované na traitech, které používá.
 *
 * Doctrine čte #[ORM\Index] a #[ORM\UniqueConstraint] jen ze samotné třídy entity, na traitu
 * je potichu ignoruje. Index ale patří ke sloupcům a sloupce do entity přináší trait - s tímhle
 * listenerem je trait nese s sebou a entita je nemusí opisovat:
 *
 * ```php
 * #[ORM\Index(fields: ['createdAt'])]
 * trait AuditLogTrait
 * {
 *     #[ORM\Column]
 *     protected DateTimeImmutable $createdAt;
 * }
 * ```
 *
 * Prochází traity entity, traity jejích rodičů (i mapped superclass) a traity použité uvnitř
 * traitů. Index, který má entita sama (stejné jméno nebo stejné sloupce ve stejném pořadí),
 * se nepřidá znovu - entita tak může index z traitu přebít vlastní definicí, třeba s jiným
 * jménem nebo volbami.
 *
 * Index bez jména pojmenuje až SchemaTool podle tabulky a sloupců, takže stejný trait na více
 * entitách nekoliduje a přesun indexu z entity na trait nezmění jeho jméno v databázi. Index
 * se jménem se přenese i se jménem - to je v rámci jedné tabulky v pořádku na MySQL, na
 * PostgreSQL jsou jména indexů unikátní v celém schématu.
 *
 * Indexy se počítají při načtení metadat a ukládají se s nimi do cache, za běhu nic nestojí.
 *
 * ```neon
 * services:
 *     - ADT\DoctrineComponents\Listeners\TraitIndexListener
 * ```
 */
class TraitIndexListener implements EventSubscriber
{
	public function getSubscribedEvents(): array
	{
		return [Events::loadClassMetadata];
	}

	public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
	{
		$metadata = $eventArgs->getClassMetadata();
		if ($metadata->isMappedSuperclass || $metadata->isEmbeddedClass) {
			return;
		}

		foreach ($this->findTraits($metadata->getReflectionClass()) as $trait) {
			foreach ($trait->getAttributes(Index::class) as $attribute) {
				$this->addConstraint($metadata, 'indexes', $attribute->newInstance(), $trait->getName());
			}
			foreach ($trait->getAttributes(UniqueConstraint::class) as $attribute) {
				$this->addConstraint($metadata, 'uniqueConstraints', $attribute->newInstance(), $trait->getName());
			}
		}
	}

	/**
	 * @return ReflectionClass[]
	 */
	private function findTraits(ReflectionClass $class): array
	{
		$traits = [];
		$queue = [];
		for ($current = $class; $current; $current = $current->getParentClass()) {
			$queue = [...$queue, ...array_values($current->getTraits())];
		}
		while ($trait = array_shift($queue)) {
			if (isset($traits[$trait->getName()])) {
				continue;
			}
			$traits[$trait->getName()] = $trait;
			$queue = [...$queue, ...array_values($trait->getTraits())];
		}

		return array_values($traits);
	}

	/**
	 * @param 'indexes'|'uniqueConstraints' $type
	 * @throws MappingException
	 */
	private function addConstraint(ClassMetadata $metadata, string $type, Index|UniqueConstraint $attribute, string $traitName): void
	{
		$constraint = [];
		if ($attribute->columns) {
			$constraint['columns'] = $attribute->columns;
		}
		if ($attribute->fields) {
			$constraint['fields'] = $attribute->fields;
		}
		if (isset($constraint['columns']) === isset($constraint['fields'])) {
			throw new MappingException(sprintf(
				'%s on trait %s (entity %s) must have either "columns" or "fields".',
				$attribute::class,
				$traitName,
				$metadata->getName()
			));
		}
		if ($attribute instanceof Index && $attribute->flags) {
			$constraint['flags'] = $attribute->flags;
		}
		if ($attribute->options) {
			$constraint['options'] = $attribute->options;
		}

		$existing = $metadata->table[$type] ?? [];
		if ($attribute->name !== null && isset($existing[$attribute->name])) {
			return;
		}
		$columns = $this->resolveColumns($metadata, $constraint);
		if ($columns === null) {
			throw new MappingException(sprintf(
				'%s on trait %s refers to a field that entity %s does not map as a column or a single-column association: %s.',
				$attribute::class,
				$traitName,
				$metadata->getName(),
				implode(', ', $constraint['fields'])
			));
		}
		foreach ($existing as $existingConstraint) {
			if ($this->resolveColumns($metadata, $existingConstraint) === $columns) {
				return;
			}
		}

		if ($attribute->name !== null) {
			$metadata->table[$type][$attribute->name] = $constraint;
		} else {
			$metadata->table[$type][] = $constraint;
		}
	}

	/**
	 * Sloupce stejně jako je odvodí SchemaTool - kvůli porovnání s indexy entity.
	 *
	 * @return string[]|null null, když některé pole entita nemapuje
	 */
	private function resolveColumns(ClassMetadata $metadata, array $constraint): ?array
	{
		if (isset($constraint['columns'])) {
			return array_values($constraint['columns']);
		}

		$columns = [];
		foreach ($constraint['fields'] ?? [] as $field) {
			if ($metadata->hasField($field)) {
				$columns[] = $metadata->getColumnName($field);
			} elseif ($metadata->hasAssociation($field) && $metadata->isAssociationWithSingleJoinColumn($field)) {
				$columns[] = $metadata->getSingleAssociationJoinColumnName($field);
			} else {
				return null;
			}
		}

		return $columns;
	}
}

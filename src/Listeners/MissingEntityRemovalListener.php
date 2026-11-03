<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Listeners;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query\FilterCollection;

/**
 * remove() entity, jejíž řádek v databázi už není, je no-op místo EntityNotFoundException.
 *
 * Typicky orphanRemoval: vazba v paměti drží neinicializovanou proxy, ale řádek mezitím smazal jiný požadavek
 * (souběžné uložení). Doctrine sama proxy při remove() nenačítá, ale listenery ano - Gedmo SoftDeleteable v onFlush
 * na ni zapisuje deletedAt - a načtení skončí EntityNotFoundException, která shodí celý flush.
 *
 * Listener proxy načte hned v preRemove. Když to nejde:
 * - řádek v DB není (ověří se přímým dotazem bez SQL filtrů) - entitu odpojí. Doctrine ji pak do mazání vůbec
 *   nenaplánuje (scheduleForDelete() přeskočí entitu mimo identity map), smazaný řádek se smazat nedá.
 * - řádek je, ale skryl ho soft-delete filtr ze $softDeleteFilters (vazba na už soft-smazaný řádek) - entitu načte
 *   s pozastaveným filtrem a odpojí; řádek zůstane soft-smazaný.
 * - řádek skryl jiný filtr - nedělá nic, mazání proběhne jako bez listeneru (Doctrine řádek smaže).
 *
 * Detach nezastaví ostatní preRemove listenery téže události - listener, který v preRemove sám čte data
 * neinicializované proxy, ji musí přeskočit (isUninitializedObject()), nebo být registrovaný až po tomhle.
 */
class MissingEntityRemovalListener implements EventSubscriber
{
	/**
	 * @param list<string> $softDeleteFilters názvy SQL filtrů, které skrývají soft-smazané řádky
	 *     (např. Gedmo SoftDeleteableFilter); řádek skrytý jiným filtrem se maže normálně
	 */
	public function __construct(
		private readonly array $softDeleteFilters = [],
	) {
	}

	public function getSubscribedEvents(): array
	{
		return [Events::preRemove];
	}

	public function preRemove(PreRemoveEventArgs $args): void
	{
		$entity = $args->getObject();
		$em = $args->getObjectManager();
		$uow = $em->getUnitOfWork();

		if (!$uow->isUninitializedObject($entity)) {
			return;
		}

		try {
			$uow->initializeObject($entity);

			return;
		} catch (EntityNotFoundException) {
		}

		if (!$this->rowExists($em, $entity)) {
			$em->detach($entity);

			return;
		}

		if ($this->initializeWithoutSoftDeleteFilters($em, $entity)) {
			$em->detach($entity);
		}
	}

	/**
	 * Existuje řádek entity v DB bez ohledu na SQL filtry? U složeného identifikátoru to neumí zjistit
	 * a vrací true - entitu pak nechá být (bezpečnější nezasahovat).
	 */
	private function rowExists(EntityManagerInterface $em, object $entity): bool
	{
		$class = $em->getClassMetadata($entity::class);
		$connection = $em->getConnection();
		$platform = $connection->getDatabasePlatform();
		$quoteStrategy = $em->getConfiguration()->getQuoteStrategy();

		$columns = $quoteStrategy->getIdentifierColumnNames($class, $platform);
		$identifier = $em->getUnitOfWork()->getEntityIdentifier($entity);
		if (count($columns) !== 1 || count($identifier) !== 1) {
			return true;
		}

		$value = reset($identifier);
		if (is_object($value)) {
			$value = $em->getUnitOfWork()->getSingleIdentifierValue($value);
		}

		$sql = sprintf(
			'SELECT 1 FROM %s WHERE %s = ?',
			$quoteStrategy->getTableName($class, $platform),
			$columns[0],
		);

		return $connection->fetchOne($sql, [$value]) !== false;
	}

	/**
	 * Načte entitu s pozastavenými soft-delete filtry. Vrací true, když se načetla - řádek skryl soft-delete filtr.
	 * suspend()/restore() filtr nezahodí (zůstanou mu parametry i nastavení), na rozdíl od disable()/enable().
	 */
	private function initializeWithoutSoftDeleteFilters(EntityManagerInterface $em, object $entity): bool
	{
		$filters = $em->getFilters();
		if (!method_exists(FilterCollection::class, 'suspend')) {
			// starší ORM bez suspend() - filtr bez ztráty stavu dočasně vypnout neumíme
			return false;
		}

		$suspended = [];
		foreach ($this->softDeleteFilters as $name) {
			if ($filters->isEnabled($name)) {
				$filters->suspend($name);
				$suspended[] = $name;
			}
		}

		if ($suspended === []) {
			return false;
		}

		try {
			$em->getUnitOfWork()->initializeObject($entity);

			return true;
		} catch (EntityNotFoundException) {
			return false;
		} finally {
			foreach ($suspended as $name) {
				$filters->restore($name);
			}
		}
	}
}

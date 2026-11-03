<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Listeners\MissingEntityRemovalListener;
use ADT\DoctrineComponents\Listeners\OrphanRemovalNestedFlushListener;
use ADT\DoctrineComponents\Tests\Fixtures\Removal\Attachment;
use ADT\DoctrineComponents\Tests\Fixtures\Removal\FakeSoftDeleteListener;
use ADT\DoctrineComponents\Tests\Fixtures\Removal\Holder;
use ADT\DoctrineComponents\Tests\Fixtures\Removal\SoftDeleteFilter;
use Doctrine\Common\EventManager;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * OrphanRemovalNestedFlushListener a MissingEntityRemovalListener nad soft-deleteable entitou
 * (FakeSoftDeleteListener a SoftDeleteFilter napodobují Gedmo SoftDeleteable).
 */
final class RemovalListenersTest extends TestCase
{
	/**
	 * Flush v postFlush po flushi, který soft-smazal orphan - bez listeneru vnořený commit maže
	 * stejný (už odpojený) orphan podruhé.
	 */
	public function testNestedFlushInPostFlushAfterOrphanRemoval(): void
	{
		foreach ([false, true] as $withListener) {
			$em = $this->createEntityManager($withListener ? [new OrphanRemovalNestedFlushListener()] : []);
			[$holder, $old] = $this->createHolderWithAttachment($em);

			$em->getEventManager()->addEventListener(Events::postFlush, new class {
				private bool $done = false;

				public function postFlush(PostFlushEventArgs $args): void
				{
					if (!$this->done) {
						$this->done = true;
						$args->getObjectManager()->flush();
					}
				}
			});

			$holder->attachment = $new = new Attachment();
			$em->persist($new);

			if (!$withListener) {
				try {
					$em->flush();
					$this->fail('Bez listeneru má vnořený flush spadnout - jinak test nic neověřuje.');
				} catch (ORMInvalidArgumentException $e) {
					$this->assertStringContainsString('Detached entity', $e->getMessage());
				}
				continue;
			}

			$em->flush();

			$this->assertNotNull($this->fetchDeletedAt($em, $old->id), 'původní příloha má být soft-smazaná');
			$this->assertSame($new->id, $this->fetchAttachmentId($em, $holder->id));
		}
	}

	/**
	 * Vazba drží neinicializovanou proxy, jejíž řádek mezitím smazal jiný požadavek.
	 */
	public function testOrphanWhoseRowIsMissing(): void
	{
		foreach ([false, true] as $withListener) {
			$em = $this->createEntityManager($withListener ? [new MissingEntityRemovalListener()] : []);
			[$holder, $old] = $this->createHolderWithAttachment($em);
			$em->clear();

			$holder = $em->find(Holder::class, $holder->id);
			$this->assertTrue($em->isUninitializedObject($holder->attachment));

			$em->getConnection()->delete('attachment', ['id' => $old->id]);

			$holder->attachment = null;

			if (!$withListener) {
				$this->expectFlushToFailWithEntityNotFound($em);
				continue;
			}

			$em->flush();

			$this->assertNull($this->fetchAttachmentId($em, $holder->id));
		}
	}

	/**
	 * Vazba odkazuje na řádek, který je už soft-smazaný - filtr ho při načtení proxy skryje.
	 * Řádek má zůstat soft-smazaný, ne se mazat natvrdo.
	 */
	public function testOrphanHiddenBySoftDeleteFilter(): void
	{
		foreach ([false, true] as $withListener) {
			$em = $this->createEntityManager($withListener ? [new MissingEntityRemovalListener()] : []);
			[$holder, $old] = $this->createHolderWithAttachment($em);
			$em->getConnection()->update('attachment', ['deleted_at' => '2026-01-01 00:00:00'], ['id' => $old->id]);
			$em->clear();

			$holder = $em->find(Holder::class, $holder->id);
			$holder->attachment = null;

			if (!$withListener) {
				$this->expectFlushToFailWithEntityNotFound($em);
				continue;
			}

			$em->flush();

			$this->assertNull($this->fetchAttachmentId($em, $holder->id));
			$this->assertSame('2026-01-01 00:00:00', $this->fetchDeletedAt($em, $old->id));
		}
	}

	/**
	 * Běžný případ: proxy jde načíst, orphan se normálně soft-smaže.
	 */
	public function testLoadableOrphanIsRemovedNormally(): void
	{
		$em = $this->createEntityManager([new MissingEntityRemovalListener(), new OrphanRemovalNestedFlushListener()]);
		[$holder, $old] = $this->createHolderWithAttachment($em);
		$em->clear();

		$holder = $em->find(Holder::class, $holder->id);
		$holder->attachment = null;
		$em->flush();

		$this->assertNull($this->fetchAttachmentId($em, $holder->id));
		$this->assertNotNull($this->fetchDeletedAt($em, $old->id));
	}

	/**
	 * @param list<EventSubscriber> $subscribers
	 */
	private function createEntityManager(array $subscribers): EntityManager
	{
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures/Removal'], true);
		$config->setNamingStrategy(new UnderscoreNamingStrategy());
		$config->enableNativeLazyObjects(true);
		$config->addFilter('softDelete', SoftDeleteFilter::class);

		$eventManager = new EventManager();
		$eventManager->addEventSubscriber(new FakeSoftDeleteListener());
		foreach ($subscribers as $subscriber) {
			$eventManager->addEventSubscriber($subscriber);
		}

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
		$em = new EntityManager($connection, $config, $eventManager);
		$em->getFilters()->enable('softDelete');

		(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

		return $em;
	}

	/**
	 * @return array{Holder, Attachment}
	 */
	private function createHolderWithAttachment(EntityManager $em): array
	{
		$holder = new Holder();
		$holder->attachment = $attachment = new Attachment();
		$em->persist($attachment);
		$em->persist($holder);
		$em->flush();

		return [$holder, $attachment];
	}

	private function expectFlushToFailWithEntityNotFound(EntityManager $em): void
	{
		try {
			$em->flush();
			$this->fail('Bez listeneru má flush spadnout - jinak test nic neověřuje.');
		} catch (EntityNotFoundException) {
			$this->addToAssertionCount(1);
		}
	}

	private function fetchAttachmentId(EntityManager $em, int $holderId): ?int
	{
		$id = $em->getConnection()->fetchOne('SELECT attachment_id FROM holder WHERE id = ?', [$holderId]);

		return $id === null ? null : (int) $id;
	}

	private function fetchDeletedAt(EntityManager $em, int $attachmentId): ?string
	{
		$deletedAt = $em->getConnection()->fetchOne('SELECT deleted_at FROM attachment WHERE id = ?', [$attachmentId]);

		return $deletedAt === false ? null : $deletedAt;
	}
}

<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\EntityManager;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\Listener\IncompleteListener;
use ADT\DoctrineComponents\Tests\Fixtures\Listener\RecordingListener;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Exception;

final class BaseListenerTest extends TestCase
{
	private EntityManager $em;

	private RecordingListener $listener;

	protected function setUp(): void
	{
		parent::setUp();

		RecordingListener::resetStaticState();
		EntityManager::$isFlushAllowed = true;

		$inner = EntityManagerFactory::create();
		$this->em = new EntityManager($inner);

		$this->listener = new RecordingListener();
		$this->listener->setEntityManager($this->em);

		$inner->getEventManager()->addEventSubscriber($this->listener);
	}

	protected function tearDown(): void
	{
		RecordingListener::resetStaticState();
		EntityManager::$isFlushAllowed = true;
		$this->em->getConnection()->close();

		parent::tearDown();
	}

	public function testItIsADoctrineEventSubscriber(): void
	{
		self::assertInstanceOf(EventSubscriber::class, $this->listener);
	}

	public function testCallbacksAreInvokedDuringFlush(): void
	{
		$this->em->persist(new Author('Adam'));
		$this->em->flush();

		self::assertContains('prePersist', $this->listener->calls);
		self::assertContains('onFlush', $this->listener->calls);
		self::assertContains('postPersist', $this->listener->calls);
	}

	public function testUpdateCallbacksAreInvoked(): void
	{
		FixtureLoader::load($this->em);
		$this->listener->calls = [];

		$this->em->find(Author::class, 1)->setName('Zmena');
		$this->em->flush();

		self::assertContains('preUpdate', $this->listener->calls);
		self::assertContains('postUpdate', $this->listener->calls);
	}

	public function testFlushIsForbiddenInsideCallbacks(): void
	{
		$this->em->persist(new Author('Adam'));
		$this->em->flush();

		self::assertSame(
			[false, false, false],
			[
				$this->listener->flushAllowedDuringCallback['prePersist'],
				$this->listener->flushAllowedDuringCallback['onFlush'],
				$this->listener->flushAllowedDuringCallback['postPersist'],
			],
		);
	}

	public function testFlushInsideAnOnFlushCallbackIsRejected(): void
	{
		$this->listener->onFlushHook = function (): void {
			$this->em->flush();
		};

		$this->em->persist(new Author('Adam'));

		$isolated = self::runIsolated(fn() => $this->em->flush());

		self::assertInstanceOf(Exception::class, $isolated['throwable']);
		self::assertSame('You cannot use flush.', $isolated['throwable']->getMessage());
	}

	public function testFlushIsAllowedAgainAfterAFailedFlush(): void
	{
		$this->em->persist(new Author('Adam'));
		$this->em->flush();

		self::assertTrue(EntityManager::$isFlushAllowed);
	}

	public function testMissingCallbackThrows(): void
	{
		$listener = new IncompleteListener();
		$listener->setEntityManager($this->em);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Implement onFlushCallback first.');

		$listener->onFlush(new OnFlushEventArgs($this->em));
	}

	public function testMissingPrePersistCallbackThrows(): void
	{
		$listener = new IncompleteListener();
		$listener->setEntityManager($this->em);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Implement prePersistCallback first.');

		$listener->prePersist(new PrePersistEventArgs(new Author('x'), $this->em));
	}

	public function testMissingPostPersistCallbackThrows(): void
	{
		$listener = new IncompleteListener();
		$listener->setEntityManager($this->em);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Implement postPersistCallback first.');

		$listener->postPersist(new PostPersistEventArgs(new Author('x'), $this->em));
	}

	public function testMissingPreUpdateCallbackThrows(): void
	{
		$listener = new IncompleteListener();
		$listener->setEntityManager($this->em);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Implement preUpdateCallback first.');

		$changeSet = [];
		$listener->preUpdate(new PreUpdateEventArgs(new Author('x'), $this->em, $changeSet));
	}

	public function testMissingPostUpdateCallbackThrows(): void
	{
		$listener = new IncompleteListener();
		$listener->setEntityManager($this->em);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Implement postUpdateCallback first.');

		$listener->postUpdate(new PostUpdateEventArgs(new Author('x'), $this->em));
	}

	public function testTransactionsOpenedInOnFlushAreClosedByPostFlush(): void
	{
		$this->listener->onFlushHook = function (): void {
			$this->listener->callStartTransaction();
			$this->listener->callStartTransaction();
		};

		$this->em->persist(new Author('Adam'));
		$this->em->flush();

		self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
	}

	public function testStartTransactionIsCountedAndOnlyStartsOnce(): void
	{
		$this->listener->callStartTransaction();
		$this->listener->callStartTransaction();

		self::assertSame(1, $this->em->getConnection()->getTransactionNestingLevel());

		$this->listener->callCommitTransaction();

		self::assertSame(1, $this->em->getConnection()->getTransactionNestingLevel());

		$this->listener->callCommitTransaction();

		self::assertSame(0, $this->em->getConnection()->getTransactionNestingLevel());
	}

	public function testCommitWithoutStartThrows(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No transactions are started for commit');

		$this->listener->callCommitTransaction();
	}

	public function testPostFlushCallbacksAreInvokedAfterFlush(): void
	{
		$invoked = 0;
		$this->listener->onFlushHook = function () use (&$invoked): void {
			$this->listener->callAddPostFlushCallback(function () use (&$invoked): void {
				$invoked++;
			});
		};

		$this->em->persist(new Author('Adam'));
		$this->em->flush();

		self::assertSame(1, $invoked);
	}

	public function testPostFlushCallbacksAreInvokedOnlyOnce(): void
	{
		$invoked = 0;
		$this->listener->onFlushHook = function () use (&$invoked): void {
			$this->listener->callAddPostFlushCallback(function () use (&$invoked): void {
				$invoked++;
			});
		};

		$this->em->persist(new Author('Adam'));
		$this->em->flush();

		$this->listener->onFlushHook = null;
		$this->em->persist(new Author('Beata'));
		$this->em->flush();

		self::assertSame(1, $invoked);
	}

	public function testAddPostFlushCallbackRequiresTheSubscribedEvent(): void
	{
		$listener = new IncompleteListener();
		$listener->setEntityManager($this->em);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Missing postFlush subsribed event.');

		(new \ReflectionMethod($listener, 'addPostFlushCallback'))->invoke($listener, static fn() => null);
	}

	public function testPostFlushDetectsUncomputedChanges(): void
	{
		FixtureLoader::load($this->em);
		RecordingListener::resetStaticState();

		$this->listener->onFlushHook = function (): void {
			$this->em->find(Author::class, 1)->setName('Zmena bez recompute');
		};

		$isolated = self::runIsolated(function (): void {
			$this->em->persist(new Author('Trigger'));
			$this->em->flush();
		});

		self::assertInstanceOf(Exception::class, $isolated['throwable']);
		self::assertStringContainsString('You probably did not recompute all changes:', $isolated['throwable']->getMessage());
	}

	public function testRecomputedChangesArePersisted(): void
	{
		FixtureLoader::load($this->em);
		RecordingListener::resetStaticState();

		$this->listener->onFlushHook = function (): void {
			$author = $this->em->find(Author::class, 1);
			$author->setName('Zmena s recompute');
			$this->listener->markForRecompute($author);
		};

		$this->em->persist(new Author('Trigger'));
		$this->em->flush();
		$this->em->clear();

		self::assertSame('Zmena s recompute', $this->em->find(Author::class, 1)->getName());
	}

	public function testNewEntitiesCreatedInOnFlushCanBeComputed(): void
	{
		$this->listener->onFlushHook = function (): void {
			$author = new Author('Vytvoreno v onFlush');
			$this->em->persist($author);
			$this->listener->markForCompute($author);
		};

		$this->em->persist(new Author('Trigger'));
		$this->em->flush();
		$this->em->clear();

		self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM author'));
	}

	public function testIsPropertyChangedDetectsAChangedProperty(): void
	{
		FixtureLoader::load($this->em);
		$detected = [];

		$this->listener->onFlushHook = function () use (&$detected): void {
			$author = $this->em->find(Author::class, 1);
			$detected['name'] = $this->listener->callIsPropertyChanged($author, 'name');
			$detected['email'] = $this->listener->callIsPropertyChanged($author, 'email');
			$detected['array'] = $this->listener->callIsPropertyChanged($author, ['email', 'name']);
		};

		$author = $this->em->find(Author::class, 1);
		$author->setName('Nove jmeno');
		$this->em->flush();

		self::assertTrue($detected['name']);
		self::assertFalse($detected['email']);
		self::assertTrue($detected['array']);
	}

	public function testIsPropertyChangedReturnsFalseForAnUnchangedEntity(): void
	{
		FixtureLoader::load($this->em);
		$detected = null;

		$this->listener->onFlushHook = function () use (&$detected): void {
			$detected = $this->listener->callIsPropertyChanged($this->em->find(Author::class, 2), 'name');
		};

		$this->em->find(Author::class, 1)->setName('Nove jmeno');
		$this->em->flush();

		self::assertFalse($detected);
	}

	public function testSubscribedEventsAreDeclared(): void
	{
		self::assertSame(
			[
				Events::onFlush,
				Events::prePersist,
				Events::postPersist,
				Events::preUpdate,
				Events::postUpdate,
				Events::postFlush,
			],
			$this->listener->getSubscribedEvents(),
		);
	}
}

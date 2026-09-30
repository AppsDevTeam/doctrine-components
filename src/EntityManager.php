<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Exception;
use ReflectionClass;
use ReflectionException;
use Throwable;

class EntityManager extends EntityManagerDecorator implements TransactionCallbacksInterface
{
	public static bool $isFlushAllowed = true;

	/** @var array<callable> */
	private array $afterCommitCallbacks = [];

	/** @var array<callable> */
	private array $afterRollbackCallbacks = [];

	public function afterCommit(callable $callback): void
	{
		if (!$this->getConnection()->isTransactionActive()) {
			$callback();
			return;
		}

		$this->afterCommitCallbacks[] = $callback;
	}

	public function afterRollback(callable $callback): void
	{
		$this->afterRollbackCallbacks[] = $callback;
	}

	public function commit(): void
	{
		parent::commit();

		// Only the outermost commit really persists anything; the nested ones just release a savepoint.
		if ($this->getConnection()->isTransactionActive()) {
			return;
		}

		$this->runTransactionCallbacks($this->afterCommitCallbacks);
	}

	public function rollback(): void
	{
		parent::rollback();

		$this->runTransactionCallbacks($this->afterRollbackCallbacks);
	}

	public function close(): void
	{
		parent::close();

		$this->afterCommitCallbacks = $this->afterRollbackCallbacks = [];
	}

	/**
	 * @param array<callable> $callbacks
	 */
	private function runTransactionCallbacks(array $callbacks): void
	{
		// clear both queues first, so a throwing callback cannot get replayed by the next transaction
		$this->afterCommitCallbacks = $this->afterRollbackCallbacks = [];

		foreach ($callbacks as $callback) {
			$callback();
		}
	}

	/**
	 * @throws Exception
	 */
	public function flush(): void
	{
		if (!self::$isFlushAllowed) {
			throw new Exception('You cannot use flush.');
		}

		// we wrap it into transaction because in onFlush event we can add something to background queue
		// in onFlush event, transaction is not started - doctrine starts transaction afterward
		// doctrine also starts only 1 transaction and then create save points
		$this->beginTransaction();
		try {
			parent::flush();
			$this->commit();
		} catch (Throwable $e) {
			$this->rollbackAfterFailedFlush();
			throw self::unwrapRootDriverException($e);
		}
	}

	/**
	 * Doctrine only rolls back its own (nested) transaction, so without this the outer transaction
	 * opened in flush() would stay open forever - and in a long-running process (queue consumer)
	 * every following flush would then only nest into it and nothing would ever be committed.
	 */
	private function rollbackAfterFailedFlush(): void
	{
		$connection = $this->getConnection();

		try {
			while ($connection->isTransactionActive()) {
				$connection->rollBack();
			}
		} catch (Throwable) {
			// The server has already discarded the whole transaction (MySQL does that after a deadlock),
			// so the savepoint DBAL still tracks does not exist any more and "ROLLBACK TO SAVEPOINT" fails
			// with "1305 SAVEPOINT DOCTRINE_n does not exist" - without resetting the DBAL nesting level.
			// Closing the connection resets it; DBAL reconnects lazily on next use.
			$connection->close();
		}

		// we roll back on the connection directly, so rollback() above has not run
		$this->runTransactionCallbacks($this->afterRollbackCallbacks);
	}

	/**
	 * When the server discards the transaction (deadlock), Doctrine's cleanup "ROLLBACK TO SAVEPOINT" fails
	 * and that failure (1305 SAVEPOINT DOCTRINE_n does not exist) is what gets thrown - the real cause
	 * (e.g. DeadlockException) is only chained as a previous exception. Rethrow the root cause instead,
	 * so it can be logged and handled (retried) properly.
	 */
	private static function unwrapRootDriverException(Throwable $e): Throwable
	{
		if (!$e instanceof DriverException) {
			return $e;
		}

		$root = $e;
		for ($previous = $e->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
			if ($previous instanceof DriverException) {
				$root = $previous;
			}
		}

		return $root;
	}

	public function isPossibleToDeleteEntity(object $entity): bool
	{
		$bool = true;
		$this->beginTransaction();

		try {
			$this->lowLevelDelete($entity);
		} catch (ForeignKeyConstraintViolationException) {
			$bool = false;
		}

		$this->rollback();

		return $bool;
	}

	protected function lowLevelDelete(object $entity): void
	{
		$class = get_class($entity);
		$this->createQueryBuilder()
			->delete()
			->from($class, 'e')
			->andWhere('e = :entity')
			->setParameter('entity', $entity)
			->getQuery()
			->execute();
	}

	/**
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public function findEntityClassByInterface(string $interfaceName): string
	{
		foreach ($this->getMetadataFactory()->getAllMetadata() as $classMetadata) {
			$className = $classMetadata->getName();
			if (new ReflectionClass($className)->implementsInterface($interfaceName)) {
				return $className;
			}
		}

		throw new Exception('There is no entity with interface "' . $interfaceName . '".');
	}

	public function getLock(string $name, int $timeout = -1): void
	{
		$this->getConnection()->executeStatement(
			'SELECT GET_LOCK(?, ?)',
			[$name, $timeout]
		);
	}

	public function releaseLock(string $name): void
	{
		$this->getConnection()->executeStatement(
			'SELECT RELEASE_LOCK(?)',
			[$name]
		);
	}
}

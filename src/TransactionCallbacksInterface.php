<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents;

/**
 * Lets you postpone a side effect that must not happen before the data is really committed,
 * typically an irreversible one outside the database (deleting a file, calling an API).
 *
 * Doctrine has no such event: postFlush is dispatched while the transaction is still open,
 * and EntityManager::flush() always opens one, so postFlush is never safe on its own.
 */
interface TransactionCallbacksInterface
{
	/**
	 * Runs the callback once the outermost transaction has been committed.
	 * Runs it immediately if no transaction is active.
	 */
	public function afterCommit(callable $callback): void;

	/**
	 * Runs the callback once the transaction has been rolled back.
	 */
	public function afterRollback(callable $callback): void;
}

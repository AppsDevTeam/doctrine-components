<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Logging;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Psr\Log\LoggerInterface;

final class Connection extends AbstractConnectionMiddleware
{
	public function __construct(private readonly ConnectionInterface $connection, private readonly LoggerInterface $logger)
	{
		parent::__construct($connection);
	}

	public function prepare(string $sql): DriverStatement
	{
		return new Statement(
			parent::prepare($sql),
			$this->logger,
			$sql,
			$this->connection
		);
	}

	public function query(string $sql): Result
	{
		$time = microtime(true);
		$result = parent::query($sql);
		$this->logger->debug(
			$sql,
			[
				'duration' => microtime(true) - $time,
			]
		);
		return $result;
	}

	public function exec(string $sql): int|string
	{
		$time = microtime(true);
		$result = parent::exec($sql);
		$this->logger->debug(
			$sql,
			[
				'duration' => microtime(true) - $time,
			]
		);
		return $result;
	}

	public function beginTransaction(): void
	{
		$time = microtime(true);
		parent::beginTransaction();
		$this->logger->debug(
			'Beginning transaction',
			[
				'duration' => microtime(true) - $time,
			]
		);
	}

	public function commit(): void
	{
		$time = microtime(true);
		parent::commit();
		$this->logger->debug(
			'Committing transaction',
			[
				'duration' => microtime(true) - $time,
			]
		);
	}

	public function rollBack(): void
	{
		$time = microtime(true);
		parent::rollBack();
		$this->logger->debug(
			'Rolling back transaction',
			[
				'duration' => microtime(true) - $time,
			]
		);
	}
}

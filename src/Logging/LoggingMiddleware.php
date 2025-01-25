<?php

declare(strict_types = 1);

namespace ADT\DoctrineComponents\Logging;

use ADT\DoctrineComponents\SqlLogger;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

final class LoggingMiddleware implements Middleware
{
	public function __construct(private readonly SqlLogger $logger)
	{
	}

	public function wrap(Driver $driver): Driver
	{
		return new \ADT\DoctrineComponents\Logging\Driver($driver, $this->logger);
	}
}

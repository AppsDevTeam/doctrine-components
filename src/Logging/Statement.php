<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Logging;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ExpandArrayParameters;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Psr\Log\LoggerInterface;

final class Statement extends AbstractStatementMiddleware
{
	/** @var array<int,mixed>|array<string,mixed> */
	private array $params = [];

	/** @var array<int,ParameterType>|array<string,ParameterType> */
	private array $types = [];

	/** @internal This statement can be only instantiated by its connection. */
	public function __construct(
		StatementInterface $statement,
		private readonly LoggerInterface $logger,
		private readonly string $sql,
		private readonly \Doctrine\DBAL\Driver\Connection $connection,
	) {
		parent::__construct($statement);
	}

	public function bindValue(int|string $param, mixed $value, ParameterType $type): void
	{
		$this->params[$param] = $value;
		$this->types[$param]  = $type;

		parent::bindValue($param, $value, $type);
	}

	public function execute(): ResultInterface
	{
		$time = microtime(true);
		$result = parent::execute();
		$this->logger->debug(
			$this->formatSql($this->sql, $this->params, $this->types),
			[
				'duration' => microtime(true) - $time
			]
		);
		return $result;
	}

	public function formatSql(string $sql, array $params, array $types): string
	{
		if ($params) {
			[$sql, $params, $types] = $this->expandListParameters($sql, $params, $types);

			// Escape % before vsprintf (example: LIKE '%ant%')
			$sql = str_replace(['%', '?'], ['%%', '%s'], $sql);

			$query = vsprintf(
				$sql,
				call_user_func(function () use ($params, $types) {
					$quotedParams = [];
					foreach ($params as $typeIndex => $value) {
						$quotedParams[] = !is_string($value) ? $value : $this->getConnection()->quote($value);
					}

					return $quotedParams;
				})
			);
		} else {
			$query = $sql;
		}

		return $query;
	}

	private function expandListParameters(string $query, array $params, array $types): array
	{
		if (!$this->needsArrayParameterConversion($params, $types)) {
			return [$query, $params, $types];
		}

		$parser = $this->getConnection()->getNativeConnection()->getDatabasePlatform()->createSQLParser();
		$visitor = new ExpandArrayParameters($params, $types);
		$parser->parse($query, $visitor);

		return [
			$visitor->getSQL(),
			$visitor->getParameters(),
			$visitor->getTypes(),
		];
	}

	/**
	 * @param array<int, mixed>|array<string, mixed>                               $params
	 * @param array<int, int|string|Type|null>|array<string, int|string|Type|null> $types
	 */
	private function needsArrayParameterConversion(array $params, array $types): bool
	{
		if (is_string(key($params))) {
			return true;
		}

		foreach ($types as $type) {
			if (
				$type === ArrayParameterType::INTEGER
				|| $type === ArrayParameterType::STRING
				|| $type === ArrayParameterType::ASCII
			) {
				return true;
			}
		}

		return false;
	}

	private function getConnection(): \Doctrine\DBAL\Driver\Connection
	{
		return $this->connection;
	}
}

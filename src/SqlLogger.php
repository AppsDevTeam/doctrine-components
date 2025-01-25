<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents;

use Psr\Log\AbstractLogger;
use Stringable;

class SqlLogger extends AbstractLogger
{
	/** @var array<int, array{sql:string, source:array, duration:int}> */
	protected array $queries = [];

	protected array $params = [];

	protected float $totalTime = 0.0;

	public function __construct(protected readonly array $sourcePaths)
	{
	}

	/**
	 * @param mixed $level
	 * @param Stringable|string $message
	 * @param array $context
	 */
	public function log(mixed $level, Stringable|string $message, array $context = []): void
	{
		if ($level === 'debug') {
			$this->queries[] = (object)[
				'sql' => $message,
				'duration' => $context['duration'],
				'source' => $this->getSource()
			];
			$this->totalTime += $context['duration'];
		} else {
			$this->params = $context['params'];
		}
	}

	public function getQueries(): array
	{
		return $this->queries;
	}

	public function getParams(): array
	{
		return $this->params;
	}

	public function getTotalTime(): float
	{
		return $this->totalTime;
	}

	public function getSource(): array
	{
		$result = [];
		if (count($this->sourcePaths) === 0) {
			return $result;
		}

		foreach (debug_backtrace() as $i) {
			if (!isset($i['file'], $i['line'])) {
				continue;
			}

			foreach ($this->sourcePaths as $path) {
				if (str_contains($i['file'], $path)) {
					$result[] = $i;
				}
			}
		}

		return $result;
	}
}

<?php

declare(strict_types = 1);

namespace ADT\DoctrineComponents\DI;

use ADT\DoctrineComponents\Logging\LoggingMiddleware;
use ADT\DoctrineComponents\SqlLogger;
use ADT\DoctrineComponents\Tracy\QueryPanel\QueryPanel;
use Nette\PhpGenerator\ClassType;
use Tracy\Bar;
use Nette\DI\Definitions\Statement;

class DbalExtension extends \Nettrine\DBAL\DI\DbalExtension
{
	public function loadConfiguration(): void
	{
		parent::loadConfiguration();

		$builder = $this->getContainerBuilder();

		// Tracy middleware
		if ($this->config->debug->panel) {
			$logger = $builder->addDefinition($this->prefix('sqlLogger'))
				->setFactory(SqlLogger::class, [$this->config->debug->sourcePaths]);

			$builder->addDefinition($this->prefix('middleware.internal.logging'))
				->setFactory(LoggingMiddleware::class, ['logger' => $logger])
				->addTag(self::MIDDLEWARE_TAG, ['connection' => 'default', 'middleware' => 'logging']);
		}
	}

	public function afterCompile(ClassType $class): void
	{
		parent::afterCompile($class);

		$builder = $this->getContainerBuilder();
		$initialization = $this->getInitialization();

		if ($this->config->debug->panel) {
			$initialization->addBody(
				$builder->formatPhp('?->addPanel(?);', [
					$builder->getDefinitionByType(Bar::class),
					new Statement(QueryPanel::class, [$builder->getDefinition($this->prefix('sqlLogger'))]),
				])
			);
		}
	}
}

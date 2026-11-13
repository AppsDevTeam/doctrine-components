<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Console\MigrateCommand;
use ADT\DoctrineComponents\DI\DoctrineComponentsExtension;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand as DoctrineMigrateCommand;
use LogicException;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerBuilder;
use Nette\DI\Definitions\Statement;
use PHPUnit\Framework\TestCase;

/**
 * `expandContract: true` swaps the class of the `migrations:migrate` definition registered by nettrine/migrations
 * (here registered by hand the same way) and keeps its name and tag.
 */
class DoctrineComponentsExtensionTest extends TestCase
{
	public function testExpandContractReplacesTheMigrateCommand(): void
	{
		$container = $this->compile(['expandContract' => true], migrateCommand: true);

		$command = $container->getService('nettrine.migrations.migrateCommand');
		$this->assertInstanceOf(MigrateCommand::class, $command);
		$this->assertSame('migrations:migrate', $command->getName());
		$this->assertSame(['nettrine.migrations.migrateCommand' => 'migrations:migrate'], $container->findByTag('console.command'));
	}

	public function testWithoutExpandContractDoctrineCommandStays(): void
	{
		$container = $this->compile([], migrateCommand: true);

		$this->assertInstanceOf(DoctrineMigrateCommand::class, $container->getService('nettrine.migrations.migrateCommand'));
	}

	public function testExpandContractNeedsTheMigrateCommand(): void
	{
		$this->expectException(LogicException::class);
		$this->expectExceptionMessage('add nettrine/migrations first');

		$this->compile(['expandContract' => true], migrateCommand: false);
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function compile(array $config, bool $migrateCommand): Container
	{
		$compiler = new Compiler();
		$compiler->addExtension('doctrineComponents', new DoctrineComponentsExtension());
		$compiler->addConfig(['doctrineComponents' => $config]);

		$builder = $compiler->getContainerBuilder();
		$builder->addDefinition('dependencyFactory')
			->setFactory(DependencyFactory::class . '::fromConnection', [
				new Statement(ConfigurationArray::class, [['migrations_paths' => ['Migrations' => sys_get_temp_dir()]]]),
				new Statement(ExistingConnection::class, [new Statement(DriverManager::class . '::getConnection', [['driver' => 'pdo_sqlite', 'memory' => true]])]),
			]);
		if ($migrateCommand) {
			$builder->addDefinition('nettrine.migrations.migrateCommand')
				->setFactory(DoctrineMigrateCommand::class)
				->setAutowired(false)
				->addTag('console.command', 'migrations:migrate');
		}

		$class = 'Container' . substr(md5(uniqid()), 0, 8);
		$compiler->setClassName($class);
		// compile() returns the code without the opening tag
		eval($compiler->compile());

		return new $class();
	}
}

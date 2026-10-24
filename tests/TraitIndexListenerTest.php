<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Listeners\TraitIndexListener;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Article;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Comment;
use ADT\DoctrineComponents\Tests\Fixtures\Invalid\Broken;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class TraitIndexListenerTest extends TestCase
{
	public function testIndexesComeFromTraitsNestedTraitsAndParents(): void
	{
		$table = $this->getTable(__DIR__ . '/Fixtures/Entity', Article::class);

		$this->assertSame(
			[['created_at'], ['external_id'], ['owner_id']],
			$this->getColumns($table, unique: false)
		);
		$this->assertSame(['code'], $table->getIndex('uniq_code')->getColumns());
		$this->assertTrue($table->getIndex('uniq_code')->isUnique());
	}

	public function testEntityOwnDefinitionWins(): void
	{
		$table = $this->getTable(__DIR__ . '/Fixtures/Entity', Comment::class);

		// created_at only under the entity's name, owner_id only once
		$this->assertSame(
			[['created_at'], ['external_id'], ['owner_id']],
			$this->getColumns($table, unique: false)
		);
		$this->assertTrue($table->hasIndex('idx_comment_created'));
		// the same name on the entity wins even with different columns
		$this->assertSame(['code', 'owner_id'], $table->getIndex('uniq_code')->getColumns());
	}

	public function testUnknownFieldFails(): void
	{
		$this->expectException(MappingException::class);
		$this->expectExceptionMessage('BrokenTrait');

		$this->getTable(__DIR__ . '/Fixtures/Invalid', Broken::class);
	}

	private function getTable(string $dir, string $entityClass): Table
	{
		$config = ORMSetup::createAttributeMetadataConfiguration([$dir], true);
		$config->setNamingStrategy(new UnderscoreNamingStrategy());
		$config->enableNativeLazyObjects(true);

		$eventManager = new EventManager();
		$eventManager->addEventSubscriber(new TraitIndexListener());

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
		$em = new EntityManager($connection, $config, $eventManager);

		$schema = (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());

		return $schema->getTable($em->getClassMetadata($entityClass)->getTableName());
	}

	/**
	 * @return list<list<string>>
	 */
	private function getColumns(Table $table, bool $unique): array
	{
		$columns = [];
		foreach ($table->getIndexes() as $index) {
			if (!$index->isPrimary() && $index->isUnique() === $unique) {
				$columns[] = $index->getColumns();
			}
		}
		sort($columns);

		return $columns;
	}
}

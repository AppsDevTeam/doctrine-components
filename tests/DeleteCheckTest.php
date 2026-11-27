<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\EntityManager;
use ADT\DoctrineComponents\Tests\Fixtures\Delete\Article;
use ADT\DoctrineComponents\Tests\Fixtures\Delete\Comment;
use ADT\DoctrineComponents\Tests\Fixtures\Delete\Tag;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager as DoctrineEntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/** EntityManager::isPossibleToDeleteEntity() nad entitou ve spojovací tabulce ManyToMany. */
final class DeleteCheckTest extends TestCase
{
	public function testEntityInJoinTableCanBeDeleted(): void
	{
		$em = $this->createEntityManager();
		$tag = new Tag();
		$article = new Article();
		$article->tags->add($tag);
		$em->persist($tag);
		$em->persist($article);
		$em->flush();

		$this->assertTrue($em->isPossibleToDeleteEntity($article), 'vlastnící strana ManyToMany');
		$this->assertTrue($em->isPossibleToDeleteEntity($tag), 'inverzní strana ManyToMany');
		$this->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM article_tag'), 'kontrola nic nesmaže');
		$this->assertTrue($em->contains($article));
	}

	public function testReferencedEntityCannotBeDeleted(): void
	{
		$em = $this->createEntityManager();
		$article = new Article();
		$em->persist($article);
		$em->persist(new Comment($article));
		$em->flush();

		$this->assertFalse($em->isPossibleToDeleteEntity($article));
		$this->assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM article'));
	}

	private function createEntityManager(): EntityManager
	{
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures/Delete'], true);
		$config->setNamingStrategy(new UnderscoreNamingStrategy());
		$config->enableNativeLazyObjects(true);

		$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
		$connection->executeStatement('PRAGMA foreign_keys = ON');
		$em = new EntityManager(new DoctrineEntityManager($connection, $config));
		(new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

		return $em;
	}
}

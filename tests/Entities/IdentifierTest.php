<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Entities;

use ADT\DoctrineComponents\Entities\Entity;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use Doctrine\DBAL\Types\Types;

final class IdentifierTest extends DatabaseTestCase
{
	public function testANewEntityHasNoId(): void
	{
		self::assertNull((new Author('x'))->getId());
	}

	public function testANewEntityIsNew(): void
	{
		self::assertTrue((new Author('x'))->isNew());
	}

	public function testAPersistedEntityIsNotNew(): void
	{
		$author = new Author('x');
		$this->em->persist($author);
		$this->em->flush();

		self::assertFalse($author->isNew());
		self::assertSame(1, $author->getId());
	}

	public function testIdIsAnIntAfterHydration(): void
	{
		$this->loadFixtures();

		$author = $this->em->find(Author::class, FixtureLoader::AUTHOR_ADAM);

		self::assertSame(1, $author->getId());
	}

	public function testCloningResetsTheId(): void
	{
		$this->loadFixtures();

		$author = $this->em->find(Author::class, FixtureLoader::AUTHOR_ADAM);
		$clone = clone $author;

		self::assertSame(1, $author->getId());
		self::assertNull($clone->getId());
		self::assertTrue($clone->isNew());
	}

	public function testACloneCanBePersistedAsANewRow(): void
	{
		$this->loadFixtures();

		$clone = clone $this->em->find(Author::class, FixtureLoader::AUTHOR_ADAM);
		$this->em->persist($clone);
		$this->em->flush();

		self::assertSame(6, $clone->getId());
		self::assertSame('Adam Novák', $clone->getName());
	}

	public function testTheIdColumnIsMappedAsAGeneratedBigintPrimaryKey(): void
	{
		$metadata = $this->em->getClassMetadata(Author::class);

		self::assertSame(['id'], $metadata->getIdentifierFieldNames());
		self::assertSame(Types::BIGINT, $metadata->getTypeOfField('id'));
		self::assertFalse($metadata->fieldMappings['id']['nullable'] ?? false);
		self::assertTrue($metadata->isIdGeneratorIdentity());
	}

	public function testTheTraitSatisfiesTheEntityInterface(): void
	{
		self::assertInstanceOf(Entity::class, new Author('x'));
	}
}

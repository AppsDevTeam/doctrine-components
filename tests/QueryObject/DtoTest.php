<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Dto\AuthorNameDto;
use ADT\DoctrineComponents\Tests\Fixtures\Dto\EmptyDto;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorNameDtoQueryObject;
use Exception;

final class DtoTest extends DatabaseTestCase
{
	protected function setUp(): void
	{
		parent::setUp();

		$this->loadFixtures();
	}

	public function testCustomSelectIsUsed(): void
	{
		self::assertDqlSame(
			'SELECT e.id AS id, e.name AS name FROM ' . Author::class . ' e ORDER BY e.id ASC',
			(new AuthorNameDtoQueryObject($this->em))->createQueryBuilder(),
		);
	}

	public function testFetchReturnsDtoInstances(): void
	{
		$result = (new AuthorNameDtoQueryObject($this->em))->fetch();

		self::assertCount(5, $result);
		self::assertContainsOnlyInstancesOf(AuthorNameDto::class, $result);
	}

	public function testDtoPropertiesArePopulated(): void
	{
		$result = (new AuthorNameDtoQueryObject($this->em))->fetch(1);

		self::assertSame(1, $result[0]->getId());
		self::assertSame('Adam Novák', $result[0]->getName());
	}

	public function testTheWholeRowIsPassedToTheConstructor(): void
	{
		$result = (new AuthorNameDtoQueryObject($this->em))->fetch(1);

		self::assertSame(['id' => 1, 'name' => 'Adam Novák'], $result[0]->constructorArgument);
	}

	public function testFiltersAndOrderApplyToDtoQueries(): void
	{
		$result = (new AuthorNameDtoQueryObject($this->em))
			->by('isActive', false)
			->orderBy('name', 'DESC')
			->fetch();

		self::assertSame(['Eva Nová', 'Cyril Velký'], array_map(fn($dto) => $dto->getName(), $result));
	}

	public function testLimitAndOffsetApplyToDtoQueries(): void
	{
		$result = (new AuthorNameDtoQueryObject($this->em))->fetch(2, 1);

		self::assertSame([2, 3], array_map(fn($dto) => $dto->getId(), $result));
	}

	public function testUnknownColumnInTheResultThrows(): void
	{
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Property ' . EmptyDto::class . '::id does not exist.');

		(new AuthorNameDtoQueryObject($this->em))->setDTOClass(EmptyDto::class)->fetch(1);
	}

	public function testDisablingTheDtoClassFallsBackToRawRows(): void
	{
		$result = (new AuthorNameDtoQueryObject($this->em))->setDTOClass(null)->fetch(1);

		self::assertSame([['id' => 1, 'name' => 'Adam Novák']], $result);
	}

	public function testDtoQueryObjectCanStillBeCounted(): void
	{
		self::assertSame(5, (new AuthorNameDtoQueryObject($this->em))->count());
	}

	public function testFetchOneReturnsADto(): void
	{
		$dto = (new AuthorNameDtoQueryObject($this->em))->byId(1)->fetchOne();

		self::assertInstanceOf(AuthorNameDto::class, $dto);
		self::assertSame('Adam Novák', $dto->getName());
	}
}

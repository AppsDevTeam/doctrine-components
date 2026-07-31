<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObjectByMode as Mode;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use PHPUnit\Framework\Attributes\DataProvider;

final class ByModeTest extends DatabaseTestCase
{
	/**
	 * @return array<string, array{0: string, 1: Mode, 2: mixed, 3: string, 4: array<string, mixed>}>
	 */
	public static function provideModes(): array
	{
		return [
			'AUTO' => ['rating', Mode::AUTO, 5, 'e.rating = :by_rating', ['by_rating' => 5]],
			'EQUALS' => ['rating', Mode::EQUALS, 5, 'e.rating = :by_rating', ['by_rating' => 5]],
			'NOT_EQUALS' => ['rating', Mode::NOT_EQUALS, 5, 'e.rating != :by_rating', ['by_rating' => 5]],
			'STARTS_WITH' => ['name', Mode::STARTS_WITH, 'Ad', 'e.name LIKE :by_name', ['by_name' => 'Ad%']],
			'ENDS_WITH' => ['name', Mode::ENDS_WITH, 'ák', 'e.name LIKE :by_name', ['by_name' => '%ák']],
			'CONTAINS' => ['name', Mode::CONTAINS, 'da', 'e.name LIKE :by_name', ['by_name' => '%da%']],
			'NOT_CONTAINS' => ['name', Mode::NOT_CONTAINS, 'da', 'e.name NOT LIKE :by_name', ['by_name' => '%da%']],
			'IS_NULL' => ['email', Mode::IS_NULL, null, 'e.email IS NULL', []],
			'IS_NOT_NULL' => ['email', Mode::IS_NOT_NULL, null, 'e.email IS NOT NULL', []],
			'IN_ARRAY' => ['rating', Mode::IN_ARRAY, [3, 5], 'e.rating IN (:by_rating)', ['by_rating' => [3, 5]]],
			'NOT_IN_ARRAY' => ['rating', Mode::NOT_IN_ARRAY, [3, 5], 'e.rating NOT IN (:by_rating)', ['by_rating' => [3, 5]]],
			'GREATER' => ['rating', Mode::GREATER, 5, 'e.rating > :by_rating', ['by_rating' => 5]],
			'GREATER_OR_EQUAL' => ['rating', Mode::GREATER_OR_EQUAL, 5, 'e.rating >= :by_rating', ['by_rating' => 5]],
			'LESS' => ['rating', Mode::LESS, 5, 'e.rating < :by_rating', ['by_rating' => 5]],
			'LESS_OR_EQUAL' => ['rating', Mode::LESS_OR_EQUAL, 5, 'e.rating <= :by_rating', ['by_rating' => 5]],
			'BETWEEN' => ['rating', Mode::BETWEEN, [3, 7], 'e.rating BETWEEN :by_rating AND :by_rating_2', ['by_rating' => 3, 'by_rating_2' => 7]],
			'NOT_BETWEEN' => ['rating', Mode::NOT_BETWEEN, [3, 7], 'e.rating NOT BETWEEN :by_rating AND :by_rating_2', ['by_rating' => 3, 'by_rating_2' => 7]],
			'MEMBER_OF' => ['tags', Mode::MEMBER_OF, 1, ':by_tags MEMBER OF e.tags', ['by_tags' => 1]],
			'NOT_MEMBER_OF' => ['tags', Mode::NOT_MEMBER_OF, 1, ':by_tags NOT MEMBER OF e.tags', ['by_tags' => 1]],
			'IS_EMPTY' => ['books', Mode::IS_EMPTY, null, 'e.books IS EMPTY', []],
			'IS_NOT_EMPTY' => ['books', Mode::IS_NOT_EMPTY, null, 'e.books IS NOT EMPTY', []],
		];
	}

	/**
	 * @param array<string, mixed> $expectedParams
	 */
	#[DataProvider('provideModes')]
	public function testModeProducesExpectedDqlAndParameters(string $column, Mode $mode, mixed $value, string $expectedCondition, array $expectedParams): void
	{
		$qb = (new AuthorQueryObject($this->em))->by($column, $value, $mode)->createQueryBuilder();

		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e WHERE ' . $expectedCondition . ' ORDER BY e.id ASC',
			$qb,
		);

		ksort($expectedParams);
		self::assertSame($expectedParams, self::paramMap($qb));
	}

	/**
	 * @param array<string, mixed> $expectedParams
	 */
	#[DataProvider('provideModes')]
	public function testModeProducesExecutableQuery(string $column, Mode $mode, mixed $value, string $expectedCondition, array $expectedParams): void
	{
		$this->loadFixtures();

		self::assertIsArray((new AuthorQueryObject($this->em))->by($column, $value, $mode)->fetch());
	}

	public function testEveryEnumCaseIsCoveredByTheProvider(): void
	{
		$covered = array_keys(self::provideModes());
		$all = array_map(static fn(Mode $mode) => $mode->name, Mode::cases());

		self::assertSame([], array_values(array_diff($all, $covered)));
	}

	public function testAutoResolvesNullToIsNull(): void
	{
		self::assertDqlContains(
			'WHERE e.email IS NULL',
			(new AuthorQueryObject($this->em))->by('email', null)->createQueryBuilder(),
		);
	}

	public function testAutoResolvesArrayToInArray(): void
	{
		self::assertDqlContains(
			'WHERE e.rating IN (:by_rating)',
			(new AuthorQueryObject($this->em))->by('rating', [1, 2])->createQueryBuilder(),
		);
	}

	public function testAutoResolvesScalarToEquals(): void
	{
		self::assertDqlContains(
			'WHERE e.name = :by_name',
			(new AuthorQueryObject($this->em))->by('name', 'Adam Novák')->createQueryBuilder(),
		);
	}

	public function testAutoResolvesEmptyArrayToInArray(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('rating', [])->createQueryBuilder();

		self::assertDqlContains('WHERE e.rating IN (:by_rating)', $qb);
		self::assertSame(['by_rating' => []], self::paramMap($qb));
	}

	public function testBetweenWithNullLowerBoundBecomesLessOrEqual(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('rating', [null, 7], Mode::BETWEEN)->createQueryBuilder();

		self::assertDqlContains('WHERE e.rating <= :by_rating', $qb);
		self::assertSame(['by_rating' => 7], self::paramMap($qb));
	}

	public function testBetweenWithNullUpperBoundBecomesGreaterOrEqual(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('rating', [3, null], Mode::BETWEEN)->createQueryBuilder();

		self::assertDqlContains('WHERE e.rating >= :by_rating', $qb);
		self::assertSame(['by_rating' => 3], self::paramMap($qb));
	}

	public function testBetweenWithBothBoundsNullDegradesToLessOrEqualNull(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('rating', [null, null], Mode::BETWEEN)->createQueryBuilder();

		self::assertDqlContains('WHERE e.rating <= :by_rating', $qb);
		self::assertSame(['by_rating' => null], self::paramMap($qb));
	}

	public function testNotBetweenKeepsBothBoundsEvenWhenOneIsNull(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('rating', [null, 7], Mode::NOT_BETWEEN)->createQueryBuilder();

		self::assertDqlContains('WHERE e.rating NOT BETWEEN :by_rating AND :by_rating_2', $qb);
		self::assertSame(['by_rating' => null, 'by_rating_2' => 7], self::paramMap($qb));
	}

	public function testNullCheckModesIgnoreTheGivenValue(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('email', 'ignored', Mode::IS_NOT_NULL)->createQueryBuilder();

		self::assertDqlContains('WHERE e.email IS NOT NULL', $qb);
		self::assertSame([], self::paramMap($qb));
	}

	public function testEmptyCheckModesIgnoreTheGivenValue(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('books', 'ignored', Mode::IS_EMPTY)->createQueryBuilder();

		self::assertDqlContains('WHERE e.books IS EMPTY', $qb);
		self::assertSame([], self::paramMap($qb));
	}

	public function testLikeModesStringifyNonStringValues(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('rating', 5, Mode::CONTAINS)->createQueryBuilder();

		self::assertSame(['by_rating' => '%5%'], self::paramMap($qb));
	}
}

<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObject;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;

final class JoinTest extends DatabaseTestCase
{
	public function testJoinTypeConstants(): void
	{
		self::assertSame('innerJoin', QueryObject::JOIN_INNER);
		self::assertSame('leftJoin', QueryObject::JOIN_LEFT);
	}

	public function testLeftJoinAddsALeftJoin(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'p');
		});

		self::assertDqlContains('LEFT JOIN e.publisher p', $qo->createQueryBuilder());
	}

	public function testInnerJoinAddsAnInnerJoin(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callInnerJoin($qb, 'e.publisher', 'p');
		});

		self::assertDqlContains('INNER JOIN e.publisher p', $qo->createQueryBuilder());
	}

	public function testInnerJoinFiltersOutRowsWithoutRelation(): void
	{
		$this->loadFixtures();

		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callInnerJoin($qb, 'e.publisher', 'p');
		});

		self::assertSame(
			[
				FixtureLoader::AUTHOR_ADAM,
				FixtureLoader::AUTHOR_BEATA,
				FixtureLoader::AUTHOR_CYRIL,
				FixtureLoader::AUTHOR_EVA,
			],
			self::idsOf($qo->fetch()),
		);
	}

	public function testRepeatedManualJoinWithTheSameAliasIsAddedOnlyOnce(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'p');
			$qo->callLeftJoin($qb, 'e.publisher', 'p');
			$qo->callLeftJoin($qb, 'e.publisher', 'p');
		});

		self::assertSame(1, substr_count(self::normalizeDql($qo->createQueryBuilder()->getDQL()), 'LEFT JOIN e.publisher p'));
	}

	public function testTheFirstJoinForAnAliasWinsSoASubclassCanRepointIt(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'x');
			$qo->callLeftJoin($qb, 'e.books', 'x');
		});

		$dql = self::normalizeDql($qo->createQueryBuilder()->getDQL());

		self::assertStringContainsString('LEFT JOIN e.publisher x', $dql);
		self::assertStringNotContainsString('LEFT JOIN e.books x', $dql);
	}

	public function testAnAliasRegisteredByAFilterAlsoWinsOverDotNotation(): void
	{
		$this->loadFixtures();

		$qo = new AuthorQueryObject($this->em);
		$qo->addFilter('repoint', function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'books');
		});
		$qo->by('books.name', 'Alfa');

		$dql = self::normalizeDql($qo->createQueryBuilder()->getDQL());

		self::assertStringContainsString('LEFT JOIN e.publisher books', $dql);
		self::assertStringNotContainsString('LEFT JOIN e.books books', $dql);
		self::assertSame([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA], self::idsOf($qo->fetch()));
	}

	public function testAManualJoinAndDotNotationOnTheSameRelationAreDeduplicated(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'publisher');
		});
		$qo->by('publisher.country', 'CZ');

		$dql = self::normalizeDql($qo->createQueryBuilder()->getDQL());

		self::assertSame(1, substr_count($dql, 'LEFT JOIN e.publisher publisher'));
		self::assertStringContainsString('publisher.country = :by_publisher_country', $dql);
	}

	public function testAJoinWithAConditionIsNotDuplicatedByDotNotation(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'publisher', Join::WITH, 'publisher.country = \'CZ\'');
		});
		$qo->by('publisher.name', 'Alfa');

		$dql = self::normalizeDql($qo->createQueryBuilder()->getDQL());

		self::assertSame(1, substr_count($dql, 'LEFT JOIN e.publisher publisher'));
		self::assertStringContainsString("WITH publisher.country = 'CZ'", $dql);
	}

	public function testInnerJoinDoesNotOverrideAnEarlierLeftJoinOnTheSameRelation(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'p');
			$qo->callInnerJoin($qb, 'e.publisher', 'p');
		});

		$dql = self::normalizeDql($qo->createQueryBuilder()->getDQL());

		self::assertStringContainsString('LEFT JOIN e.publisher p', $dql);
		self::assertStringNotContainsString('INNER JOIN', $dql);
	}

	public function testManualJoinAcceptsConditionTypeAndCondition(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.books', 'b', Join::WITH, 'b.price > 100');
		});

		self::assertDqlContains('LEFT JOIN e.books b WITH b.price > 100', $qo->createQueryBuilder());
	}

	public function testManualJoinReturnsTheQueryObject(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$returned = null;
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo, &$returned) {
			$returned = $qo->callLeftJoin($qb, 'e.publisher', 'p');
		});

		$qo->createQueryBuilder();

		self::assertSame($qo, $returned);
	}

	public function testManualJoinPrefixesAJoinWithoutDot(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'publisher', 'p');
		});

		self::assertDqlContains('LEFT JOIN e.publisher p', $qo->createQueryBuilder());
	}

	public function testJoinRegistryIsResetForEachQueryBuilder(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function (QueryBuilder $qb) use ($qo) {
			$qo->callLeftJoin($qb, 'e.publisher', 'p');
		});

		$first = self::normalizeDql($qo->createQueryBuilder()->getDQL());
		$second = self::normalizeDql($qo->createQueryBuilder()->getDQL());

		self::assertSame($first, $second);
		self::assertStringContainsString('LEFT JOIN e.publisher p', $second);
	}

	public function testAddJoinsIgnoresColumnsWithoutDot(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qb = $qo->createQueryBuilder(false);

		$qo->callAddJoins($qb, ['name', 'email']);

		self::assertDqlSame('SELECT FROM ' . Author::class . ' e', $qb);
	}

	public function testAddJoinsCreatesOneJoinPerPathSegmentExceptTheLast(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qb = $qo->createQueryBuilder(false);

		$qo->callAddJoins($qb, ['books.author.publisher.name']);

		self::assertDqlSame(
			'SELECT FROM ' . Author::class . ' e LEFT JOIN e.books books'
			. ' LEFT JOIN books.author author LEFT JOIN author.publisher publisher',
			$qb,
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideColumnPrefixes(): array
	{
		return [
			'plain column' => ['name', 'e.name'],
			'already prefixed' => ['e.name', 'e.name'],
			'joined alias' => ['publisher.name', 'publisher.name'],
			'class name' => ['App\\Entity\\Author', 'App\\Entity\\Author'],
			'empty string' => ['', 'e.'],
		];
	}

	#[DataProvider('provideColumnPrefixes')]
	public function testAddColumnPrefix(string $column, string $expected): void
	{
		self::assertSame($expected, (new AuthorQueryObject($this->em))->callAddColumnPrefix($column));
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provideJoinedEntityColumnNames(): array
	{
		return [
			'two segments' => ['e.name', 'e.name'],
			'three segments' => ['e.publisher.name', 'publisher.name'],
			'four segments' => ['e.books.author.name', 'author.name'],
			'single segment' => ['name', 'name'],
		];
	}

	#[DataProvider('provideJoinedEntityColumnNames')]
	public function testGetJoinedEntityColumnName(string $column, string $expected): void
	{
		self::assertSame($expected, (new AuthorQueryObject($this->em))->callGetJoinedEntityColumnName($column));
	}
}

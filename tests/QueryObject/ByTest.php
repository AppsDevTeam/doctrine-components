<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObjectByMode as Mode;
use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Tag;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use DateTimeImmutable;
use Exception;

final class ByTest extends DatabaseTestCase
{
	public function testSingleColumnFiltersRows(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('isActive', false)->fetch();

		self::assertSame([FixtureLoader::AUTHOR_CYRIL, FixtureLoader::AUTHOR_EVA], self::idsOf($authors));
	}

	public function testMultipleColumnsAreCombinedWithOr(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by(['name', 'email'], 'x', Mode::CONTAINS)->createQueryBuilder();

		self::assertDqlContains('WHERE e.name LIKE :by_name OR e.email LIKE :by_email', $qb);
		self::assertSame(['by_email' => '%x%', 'by_name' => '%x%'], self::paramMap($qb));
	}

	public function testMultipleColumnsMatchRowsFromEitherColumn(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by(['name', 'email'], 'eva', Mode::CONTAINS)->fetch();

		self::assertSame([FixtureLoader::AUTHOR_EVA], self::idsOf($authors));
	}

	public function testSeparateByCallsAreCombinedWithAnd(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('isActive', true)
			->by('rating', 5, Mode::GREATER_OR_EQUAL)
			->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_DAVID], self::idsOf($authors));
	}

	public function testStringValueIsNotTreatedAsIterable(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('name', 'abc')->createQueryBuilder();

		self::assertDqlContains('WHERE e.name = :by_name', $qb);
		self::assertSame(['by_name' => 'abc'], self::paramMap($qb));
	}

	public function testRepeatedFilterOnTheSameColumnUsesUniqueParameterNames(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->by('name', 'a', Mode::CONTAINS)
			->by('name', 'b', Mode::CONTAINS)
			->by('name', 'c', Mode::CONTAINS)
			->createQueryBuilder();

		self::assertDqlContains('WHERE e.name LIKE :by_name AND e.name LIKE :by_name_2 AND e.name LIKE :by_name_3', $qb);
		self::assertSame(
			['by_name' => '%a%', 'by_name_2' => '%b%', 'by_name_3' => '%c%'],
			self::paramMap($qb),
		);
	}

	public function testRepeatedFilterOnTheSameColumnKeepsAllConditionsEffective(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('name', 'a', Mode::CONTAINS)
			->by('name', 'Nov', Mode::CONTAINS)
			->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_EVA], self::idsOf($authors));
	}

	public function testRepeatedBetweenFilterReservesBothParameterNames(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->by('rating', [1, 2], Mode::BETWEEN)
			->by('rating', [3, 4], Mode::BETWEEN)
			->createQueryBuilder();

		self::assertDqlContains(
			'WHERE (e.rating BETWEEN :by_rating AND :by_rating_2) AND (e.rating BETWEEN :by_rating_3 AND :by_rating_3_2)',
			$qb,
		);
		self::assertSame(
			['by_rating' => 1, 'by_rating_2' => 2, 'by_rating_3' => 3, 'by_rating_3_2' => 4],
			self::paramMap($qb),
		);
	}

	public function testEqualsFollowedByBetweenDoesNotCollide(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->by('rating', 1)
			->by('rating', [3, 4], Mode::BETWEEN)
			->createQueryBuilder();

		self::assertDqlContains(
			'WHERE e.rating = :by_rating AND (e.rating BETWEEN :by_rating_2 AND :by_rating_2_2)',
			$qb,
		);
		self::assertSame(
			['by_rating' => 1, 'by_rating_2' => 3, 'by_rating_2_2' => 4],
			self::paramMap($qb),
		);
	}

	public function testBetweenFollowedByEqualsSkipsTheReservedSecondName(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->by('rating', [1, 2], Mode::BETWEEN)
			->by('rating', 9)
			->createQueryBuilder();

		self::assertDqlContains(
			'WHERE (e.rating BETWEEN :by_rating AND :by_rating_2) AND e.rating = :by_rating_3',
			$qb,
		);
		self::assertSame(
			['by_rating' => 1, 'by_rating_2' => 2, 'by_rating_3' => 9],
			self::paramMap($qb),
		);
	}

	public function testParameterNamesOfDifferentColumnsDoNotInterfere(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->by('name', 'a')
			->by('email', 'b')
			->by('name', 'c')
			->createQueryBuilder();

		self::assertSame(
			['by_email' => 'b', 'by_name' => 'a', 'by_name_2' => 'c'],
			self::paramMap($qb),
		);
	}

	public function testDotNotationAddsLeftJoinAndUsesTheJoinedAlias(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('publisher.country', 'CZ')->createQueryBuilder();

		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e LEFT JOIN e.publisher publisher'
			. ' WHERE publisher.country = :by_publisher_country ORDER BY e.id ASC',
			$qb,
		);
	}

	public function testDotNotationFiltersByJoinedColumn(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('publisher.country', 'CZ')->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA], self::idsOf($authors));
	}

	public function testDeepDotNotationAddsAllIntermediateJoins(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('books.author.name', 'x')->createQueryBuilder();

		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e LEFT JOIN e.books books LEFT JOIN books.author author'
			. ' WHERE author.name = :by_books_author_name ORDER BY e.id ASC',
			$qb,
		);
	}

	public function testJoinIsAddedOnlyOnceForRepeatedFilters(): void
	{
		$qb = (new AuthorQueryObject($this->em))
			->by('publisher.country', 'CZ')
			->by('publisher.name', 'Alfa')
			->createQueryBuilder();

		self::assertSame(1, substr_count(self::normalizeDql($qb->getDQL()), 'LEFT JOIN e.publisher publisher'));
	}

	public function testJoinedAndPlainColumnsCanBeMixedInOneCall(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by(['name', 'publisher.name'], 'Alfa', Mode::CONTAINS)->createQueryBuilder();

		self::assertDqlSame(
			'SELECT e FROM ' . Author::class . ' e LEFT JOIN e.publisher publisher'
			. ' WHERE e.name LIKE :by_name OR publisher.name LIKE :by_publisher_name ORDER BY e.id ASC',
			$qb,
		);
	}

	public function testJoinedFilterReturnsEachRootEntityOnlyOnce(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('books.title', 'kniha', Mode::CONTAINS)->fetch();

		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf($authors),
		);
	}

	public function testMemberOfAcceptsAnEntity(): void
	{
		$this->loadFixtures();

		$tag = $this->em->find(Tag::class, FixtureLoader::TAG_PHP);
		$authors = (new AuthorQueryObject($this->em))->by('tags', $tag, Mode::MEMBER_OF)->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_EVA], self::idsOf($authors));
	}

	public function testNotMemberOfAcceptsAnEntity(): void
	{
		$this->loadFixtures();

		$tag = $this->em->find(Tag::class, FixtureLoader::TAG_PHP);
		$authors = (new AuthorQueryObject($this->em))->by('tags', $tag, Mode::NOT_MEMBER_OF)->fetch();

		self::assertSame(
			[FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_CYRIL, FixtureLoader::AUTHOR_DAVID],
			self::idsOf($authors),
		);
	}

	public function testIsEmptyMatchesEntitiesWithoutRelatedRows(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('books', null, Mode::IS_EMPTY)->fetch();

		self::assertSame([FixtureLoader::AUTHOR_CYRIL, FixtureLoader::AUTHOR_EVA], self::idsOf($authors));
	}

	public function testIsNotEmptyMatchesEntitiesWithRelatedRows(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('books', null, Mode::IS_NOT_EMPTY)->fetch();

		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf($authors),
		);
	}

	public function testInArrayWithEmptyArrayMatchesNothing(): void
	{
		$this->loadFixtures();

		self::assertSame([], (new AuthorQueryObject($this->em))->by('rating', [], Mode::IN_ARRAY)->fetch());
	}

	public function testBetweenIsInclusive(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('rating', [3, 7], Mode::BETWEEN)->fetch();

		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_EVA],
			self::idsOf($authors),
		);
	}

	public function testNotBetweenExcludesNullValues(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('rating', [3, 7], Mode::NOT_BETWEEN)->fetch();

		self::assertSame([FixtureLoader::AUTHOR_DAVID], self::idsOf($authors));
	}

	public function testDateColumnsCanBeFilteredWithAStringValue(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('birthDate', '1980-01-15')->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM], self::idsOf($authors));
	}

	public function testDateRangesCanBeFilteredWithStringValues(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('birthDate', ['1979-01-01', '1991-01-01'], Mode::BETWEEN)
			->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA], self::idsOf($authors));
	}

	public function testANullDateIsMatchedByIsNull(): void
	{
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))->by('birthDate', null)->fetch();

		self::assertSame([FixtureLoader::AUTHOR_CYRIL], self::idsOf($authors));
	}

	public function testADateTimeObjectMatchesADateColumnOnMysql(): void
	{
		self::requireMysql();
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('birthDate', new DateTimeImmutable('1980-01-15'))
			->fetch();

		self::assertSame([FixtureLoader::AUTHOR_ADAM], self::idsOf($authors));
	}

	public function testADateTimeObjectMatchesNothingOnSqlite(): void
	{
		self::requireSqlite();
		$this->loadFixtures();

		$authors = (new AuthorQueryObject($this->em))
			->by('birthDate', new DateTimeImmutable('1980-01-15'))
			->fetch();

		self::assertSame([], $authors);
	}

	public function testValueIsCapturedWhenByIsCalledNotWhenTheFilterRuns(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$value = 'first';

		$qo->by('name', $value);
		$value = 'second';

		self::assertSame(['by_name' => 'first'], self::paramMap($qo->createQueryBuilder()));
	}

	public function testEntityAliasInAColumnNameIsRejected(): void
	{
		$qo = (new AuthorQueryObject($this->em))->by('e.name', 'x');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Do not use entity alias in field names.');

		$qo->createQueryBuilder();
	}

	public function testEntityAliasIsRejectedEvenAmongValidColumns(): void
	{
		$qo = (new AuthorQueryObject($this->em))->by(['name', 'e.email'], 'x');

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Do not use entity alias in field names.');

		$qo->createQueryBuilder();
	}

	public function testAColumnStartingWithTheAliasNameIsNotRejected(): void
	{
		$qb = (new AuthorQueryObject($this->em))->by('email', 'x')->createQueryBuilder();

		self::assertDqlContains('WHERE e.email = :by_email', $qb);
	}

	public function testAFilterCanBeRegisteredUnderANamedKeyAndDisabled(): void
	{
		$this->loadFixtures();

		$qo = (new AuthorQueryObject($this->em))->by('isActive', true, Mode::AUTO, 'my_filter');

		self::assertSame(['my_filter'], $qo->getFilterKeys());
		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf($qo->fetch()),
		);

		$qo->disableFilter('my_filter');

		self::assertSame([], $qo->getFilterKeys());
		self::assertSame([1, 2, 3, 4, 5], self::idsOf($qo->fetch()));
	}

	public function testANamedFilterKeyIsOverwrittenByASecondCallWithTheSameKey(): void
	{
		$this->loadFixtures();

		$qo = (new AuthorQueryObject($this->em))
			->by('isActive', true, Mode::AUTO, 'my_filter')
			->by('isActive', false, Mode::AUTO, 'my_filter');

		self::assertSame(['my_filter'], $qo->getFilterKeys());
		self::assertSame([FixtureLoader::AUTHOR_CYRIL, FixtureLoader::AUTHOR_EVA], self::idsOf($qo->fetch()));
	}

	public function testWithoutAFilterKeyEveryCallAddsANewFilter(): void
	{
		$qo = (new AuthorQueryObject($this->em))->by('name', 'a')->by('email', 'b');

		self::assertSame([0, 1], $qo->getFilterKeys());
	}
}

<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Author;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\NamedFilterAuthorQueryObject;
use Doctrine\ORM\QueryBuilder;

final class FilterTest extends DatabaseTestCase
{
	public function testFiltersFromInitAreApplied(): void
	{
		$this->loadFixtures();

		$authors = (new NamedFilterAuthorQueryObject($this->em))->fetch();

		self::assertSame(
			[FixtureLoader::AUTHOR_ADAM, FixtureLoader::AUTHOR_BEATA, FixtureLoader::AUTHOR_DAVID],
			self::idsOf($authors),
		);
	}

	public function testNamedFiltersKeepTheirKeys(): void
	{
		self::assertSame(
			[AuthorQueryObject::FILTER_ACTIVE, AuthorQueryObject::FILTER_NAMED],
			(new NamedFilterAuthorQueryObject($this->em))->getFilterKeys(),
		);
	}

	public function testDisableFilterRemovesASingleFilter(): void
	{
		$this->loadFixtures();

		$authors = (new NamedFilterAuthorQueryObject($this->em))
			->disableFilter(AuthorQueryObject::FILTER_ACTIVE)
			->fetch();

		self::assertSame([1, 2, 3, 4, 5], self::idsOf($authors));
	}

	public function testDisableFilterRemovesMultipleFilters(): void
	{
		$qo = (new NamedFilterAuthorQueryObject($this->em))
			->disableFilter([AuthorQueryObject::FILTER_ACTIVE, AuthorQueryObject::FILTER_NAMED]);

		self::assertSame([], $qo->getFilterKeys());
		self::assertDqlSame('SELECT e FROM ' . Author::class . ' e ORDER BY e.id ASC', $qo->createQueryBuilder());
	}

	public function testDisableFilterWithAnUnknownKeyIsANoop(): void
	{
		$qo = (new NamedFilterAuthorQueryObject($this->em))->disableFilter('does_not_exist');

		self::assertSame(
			[AuthorQueryObject::FILTER_ACTIVE, AuthorQueryObject::FILTER_NAMED],
			$qo->getFilterKeys(),
		);
	}

	public function testFiltersAreAppliedInInsertionOrder(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$order = [];

		$qo->addFilter('first', function () use (&$order) {
			$order[] = 'first';
		});
		$qo->addFilter('second', function () use (&$order) {
			$order[] = 'second';
		});

		$qo->createQueryBuilder();

		self::assertSame(['first', 'second'], $order);
	}

	public function testFilterCallbackIsBoundToTheQueryObject(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$boundTo = null;

		$qo->addAnonymousFilter(function () use (&$boundTo) {
			$boundTo = $this;
		});

		$qo->createQueryBuilder();

		self::assertSame($qo, $boundTo);
	}

	public function testFilterReceivesTheQueryBuilder(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$received = null;

		$qo->addAnonymousFilter(function (QueryBuilder $qb) use (&$received) {
			$received = $qb;
		});

		$qb = $qo->createQueryBuilder();

		self::assertSame($qb, $received);
	}

	public function testAFilterCanRegisterAnotherFilter(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function () use ($qo) {
			$qo->by('isActive', true);
		});

		self::assertDqlContains('WHERE e.isActive = :by_isActive', $qo->createQueryBuilder());
	}

	public function testAFilterRegisteredByAnotherFilterIsAppliedToResults(): void
	{
		$this->loadFixtures();

		$qo = new AuthorQueryObject($this->em);
		$qo->addAnonymousFilter(function () use ($qo) {
			$qo->by('isActive', false);
		});

		self::assertSame([FixtureLoader::AUTHOR_CYRIL, FixtureLoader::AUTHOR_EVA], self::idsOf($qo->fetch()));
	}

	public function testFiltersRunOnEveryQueryBuilderCreation(): void
	{
		$qo = new AuthorQueryObject($this->em);
		$calls = 0;

		$qo->addAnonymousFilter(function () use (&$calls) {
			$calls++;
		});

		$qo->createQueryBuilder();
		$qo->createQueryBuilder();

		self::assertSame(2, $calls);
	}

	public function testFiltersAreAppliedEvenWithoutSelectAndOrder(): void
	{
		self::assertDqlContains(
			'WHERE e.isActive = :by_isActive',
			(new AuthorQueryObject($this->em))->by('isActive', true)->createQueryBuilder(false),
		);
	}
}

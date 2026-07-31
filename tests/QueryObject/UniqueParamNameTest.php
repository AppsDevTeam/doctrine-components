<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\QueryObject;

use ADT\DoctrineComponents\Tests\DatabaseTestCase;
use ADT\DoctrineComponents\Tests\Fixtures\QueryObject\AuthorQueryObject;
use Doctrine\ORM\QueryBuilder;

final class UniqueParamNameTest extends DatabaseTestCase
{
	private AuthorQueryObject $qo;

	private QueryBuilder $qb;

	protected function setUp(): void
	{
		parent::setUp();

		$this->qo = new AuthorQueryObject($this->em);
		$this->qb = $this->em->createQueryBuilder();
	}

	public function testUnusedNameIsReturnedUnchanged(): void
	{
		self::assertSame('by_name', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
	}

	public function testUsedNameGetsASuffix(): void
	{
		$this->qb->setParameter('by_name', 'x');

		self::assertSame('by_name_2', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
	}

	public function testSuffixIsIncrementedUntilTheNameIsFree(): void
	{
		$this->qb->setParameter('by_name', 'a');
		$this->qb->setParameter('by_name_2', 'b');
		$this->qb->setParameter('by_name_3', 'c');

		self::assertSame('by_name_4', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
	}

	public function testTheLowestFreeSuffixIsUsed(): void
	{
		$this->qb->setParameter('by_name', 'a');
		$this->qb->setParameter('by_name_3', 'c');

		self::assertSame('by_name_2', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
	}

	public function testAParameterExplicitlySetToNullStillCountsAsUsed(): void
	{
		$this->qb->setParameter('by_name', null);

		self::assertSame('by_name_2', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
	}

	public function testTheSecondNameIsOnlyCheckedWhenRequested(): void
	{
		$this->qb->setParameter('by_name_2', 'x');

		self::assertSame('by_name', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
		self::assertSame('by_name_3', $this->qo->callGetUniqueParamName($this->qb, 'by_name', true));
	}

	public function testSecondParameterReservationSkipsColidingPairs(): void
	{
		$this->qb->setParameter('by_name', 'a');
		$this->qb->setParameter('by_name_2', 'b');

		self::assertSame('by_name_3', $this->qo->callGetUniqueParamName($this->qb, 'by_name', true));
	}

	public function testDifferentBaseNamesAreIndependent(): void
	{
		$this->qb->setParameter('by_name', 'a');

		self::assertSame('by_email', $this->qo->callGetUniqueParamName($this->qb, 'by_email'));
	}

	public function testCallIsSideEffectFreeSoRepeatedCallsReturnTheSameName(): void
	{
		self::assertSame('by_name', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
		self::assertSame('by_name', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
	}

	public function testUnrelatedParametersDoNotAffectTheResult(): void
	{
		$this->qb->setParameter('byIdFilter', [1]);
		$this->qb->setParameter('init_isActive', true);

		self::assertSame('by_name', $this->qo->callGetUniqueParamName($this->qb, 'by_name'));
	}
}

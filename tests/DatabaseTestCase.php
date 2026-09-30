<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Tests\Fixtures\EntityManagerFactory;
use ADT\DoctrineComponents\Tests\Fixtures\FixtureLoader;
use Doctrine\ORM\EntityManagerInterface;

abstract class DatabaseTestCase extends TestCase
{
	protected EntityManagerInterface $em;

	protected function setUp(): void
	{
		parent::setUp();

		$this->em = EntityManagerFactory::create();
	}

	protected function tearDown(): void
	{
		if (isset($this->em)) {
			$this->em->getConnection()->close();
		}

		parent::tearDown();
	}

	protected function loadFixtures(): void
	{
		FixtureLoader::load($this->em);
	}
}

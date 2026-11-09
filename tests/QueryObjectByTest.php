<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\QueryObject\QueryObjectByMode;
use ADT\DoctrineComponents\Tests\Fixtures\By\Device;
use ADT\DoctrineComponents\Tests\Fixtures\By\Log;
use ADT\DoctrineComponents\Tests\Fixtures\By\LogQuery;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * `by()` nad sloupcem, který není textový.
 *
 * PostgreSQL neporovná LIKE s číslem (operator does not exist: integer ~~ unknown) a nečíselnou
 * hodnotu s celočíselným sloupcem (invalid input syntax for type integer). Hodnoty filtru
 * přitom chodí z gridů jako text, takže "obsahuje 200" nebo "rovná se abc" nad stavovým kódem
 * musí projít na každé platformě.
 */
final class QueryObjectByTest extends TestCase
{
	private EntityManager $em;

	protected function setUp(): void
	{
		$this->em = $this->createEntityManager(['driver' => 'pdo_sqlite', 'memory' => true]);
		(new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

		$device = new Device();
		$device->number = 1234;
		$this->em->persist($device);

		foreach ([200, 400, 401, 404, 500, null] as $code) {
			$log = new Log();
			$log->url = '/orders/' . $code;
			$log->code = $code;
			$log->device = $code === 401 ? $device : null;
			$this->em->persist($log);
		}

		$this->em->flush();
		$this->em->clear();
	}

	public function testLikeOnIntegerColumnComparesItsText(): void
	{
		$query = $this->query()->by('code', '20', QueryObjectByMode::NOT_CONTAINS);
		$this->assertStringContainsString("CONCAT(e.code, '') NOT LIKE", $this->getDql($query));
		$this->assertSame([400, 401, 404, 500], $this->codes($query));

		$this->assertSame([400, 401, 404], $this->codes($this->query()->by('code', '4', QueryObjectByMode::STARTS_WITH)));
		$this->assertSame([401], $this->codes($this->query()->by('code', '1', QueryObjectByMode::ENDS_WITH)));
		$this->assertSame([200, 400, 500], $this->codes($this->query()->by('code', '00', QueryObjectByMode::CONTAINS)));
	}

	public function testLikeOnJoinedIntegerColumn(): void
	{
		$query = $this->query()->by('device.number', '12', QueryObjectByMode::STARTS_WITH);

		$this->assertStringContainsString("CONCAT(device.number, '') LIKE", $this->getDql($query));
		$this->assertSame([401], $this->codes($query));
	}

	public function testLikeOnStringColumnStaysPlain(): void
	{
		// obalený sloupec by v MySQL přišel o index u "začíná na"
		$query = $this->query()->by('url', '/orders/4', QueryObjectByMode::STARTS_WITH);

		$this->assertStringContainsString('e.url LIKE', $this->getDql($query));
		$this->assertSame([400, 401, 404], $this->codes($query));
	}

	public function testEqualityWithValueThatCannotBeInIntegerColumn(): void
	{
		// MySQL by "abc" převedl na 0, PostgreSQL by dotaz shodil - nerovná se ničemu
		$this->assertSame([], $this->codes($this->query()->by('code', 'abc')));
		$this->assertSame([], $this->codes($this->query()->by('code', '2.5')));
		$this->assertSame([], $this->codes($this->query()->by('code', '99999999999')));

		// negace platí pro každou vyplněnou hodnotu, NULL vylučuje stejně jako `!=`
		$this->assertSame([200, 400, 401, 404, 500], $this->codes($this->query()->by('code', 'abc', QueryObjectByMode::NOT_EQUALS)));
	}

	public function testListKeepsOnlyValuesThatFitTheColumn(): void
	{
		$this->assertSame([200, 401], $this->codes($this->query()->by('code', ['200', 'abc', ' 401 '])));
		$this->assertSame([], $this->codes($this->query()->by('code', ['abc'], QueryObjectByMode::IN_ARRAY)));
		$this->assertSame([400, 401, 404, 500], $this->codes($this->query()->by('code', ['200', 'abc'], QueryObjectByMode::NOT_IN_ARRAY)));
		$this->assertSame([200, 400, 401, 404, 500], $this->codes($this->query()->by('code', ['abc'], QueryObjectByMode::NOT_IN_ARRAY)));
	}

	public function testRangeFollowsColumnType(): void
	{
		// smallint unsigned: 0..65535
		$this->assertSame(6, $this->query()->by('priority', '0')->count());
		$this->assertSame(0, $this->query()->by('priority', '-1')->count());
		$this->assertSame(0, $this->query()->by('priority', '65535')->count());
		$this->assertSame(0, $this->query()->by('priority', '65536')->count());
		$this->assertStringContainsString('1 = 0', $this->getDql($this->query()->by('priority', '65536')));
		$this->assertStringNotContainsString('1 = 0', $this->getDql($this->query()->by('priority', '65535')));
	}

	public function testValidIntegerStringStillMatches(): void
	{
		$this->assertSame([401], $this->codes($this->query()->by('code', '401')));
		$this->assertSame([401], $this->codes($this->query()->by('code', 401)));
	}

	private function query(): LogQuery
	{
		return new LogQuery($this->em);
	}

	/**
	 * @return list<int|null>
	 */
	private function codes(LogQuery $query): array
	{
		return array_map(fn(Log $log) => $log->code, $query->fetch());
	}

	private function getDql(LogQuery $query): string
	{
		return $query->createQueryBuilder()->getDQL();
	}

	private function createEntityManager(array $params): EntityManager
	{
		$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/Fixtures/By'], true);
		$config->setNamingStrategy(new UnderscoreNamingStrategy());
		$config->enableNativeLazyObjects(true);

		return new EntityManager(DriverManager::getConnection($params, $config), $config);
	}
}

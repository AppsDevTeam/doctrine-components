<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Middleware;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use LogicException;
use mysqli;
use PDO;
use SensitiveParameter;

/**
 * Nastaví spojení časovou zónu aplikace, jakmile se otevře.
 *
 * Typicky kvůli úložišti logů: logy se ukládají v UTC a sloupec `created_at` je v PostgreSQL
 * TIMESTAMPTZ. Bez tohohle vrací databáze hodnoty v zóně serveru (obvykle UTC), takže by
 * administrace ukazovala UTC - v létě o dvě hodiny zpátky proti tomu, co uživatel čekal.
 * Se zónou aplikace vrátí databáze týž okamžik s jejím offsetem a gridy zobrazí čas
 * projektu, aniž by o tom kterýkoli z nich musel vědět.
 *
 * Řeší to databáze, ne PHP, takže je správně i přechod mezi letním a zimním časem: offset
 * se odvodí ke každému záznamu zvlášť podle jeho data.
 *
 * Uložené hodnoty to nemění - jde jen o to, v čem se čtou.
 *
 * PostgreSQL i MySQL. Na MySQL se to týká jen sloupců TIMESTAMP (DATETIME zónu nemá)
 * a pojmenované zóny jako `Europe/Prague` vyžadují načtené tabulky časových zón
 * (`mysql_tzinfo_to_sql`), jinak spojení spadne na "Unknown or incorrect time zone";
 * offset typu `+01:00` funguje vždy.
 *
 * ```neon
 * nettrine.dbal:
 *     connections:
 *         logdb:
 *             middlewares:
 *                 timeZone: ADT\DoctrineComponents\Middleware\SessionTimeZoneMiddleware(%timeZone%)
 * ```
 */
final readonly class SessionTimeZoneMiddleware implements Middleware
{
	public function __construct(private string $timeZone)
	{
	}

	public function wrap(Driver $driver): Driver
	{
		return new class ($driver, $this->timeZone) extends AbstractDriverMiddleware {
			public function __construct(Driver $driver, private readonly string $timeZone)
			{
				parent::__construct($driver);
			}

			public function connect(#[SensitiveParameter] array $params): DriverConnection
			{
				$connection = parent::connect($params);
				$connection->exec(SessionTimeZoneMiddleware::getStatement($connection, $this->timeZone));

				return $connection;
			}
		};
	}

	/**
	 * Syntaxe se liší: PostgreSQL `SET TIME ZONE`, MySQL `SET time_zone =`. Platforma se
	 * pozná podle nativního spojení, ne podle parametrů - ty můžou nést `driverClass`
	 * místo názvu ovladače.
	 *
	 * @internal
	 */
	public static function getStatement(DriverConnection $connection, string $timeZone): string
	{
		$native = $connection->getNativeConnection();
		$platform = match (true) {
			$native instanceof PDO => $native->getAttribute(PDO::ATTR_DRIVER_NAME),
			$native instanceof mysqli => 'mysql',
			$native instanceof \PgSql\Connection => 'pgsql',
			default => null,
		};

		return match ($platform) {
			'pgsql' => 'SET TIME ZONE ' . $connection->quote($timeZone),
			'mysql' => 'SET time_zone = ' . $connection->quote($timeZone),
			default => throw new LogicException(sprintf('SessionTimeZoneMiddleware supports PostgreSQL and MySQL only, got %s.', get_debug_type($native))),
		};
	}
}

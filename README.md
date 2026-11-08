# Doctrine Components

## Install

```
composer require adt/doctrine-components
```

## Creating a QueryObject class

```php
/**
 * Annotations "extends" and "implements" and interface "FetchInterface" are used for PhpStorm code completion and PHPStan.
 * 
 * @extends QueryObject<Profile>
 * @implements FetchInterface<Profile>
 */
class ProfileQueryObject extends QueryObject implements FetchInterface
{
	const FILTER_SECURITY = 'filter_security';
	const FILTER_IS_ACTIVE = 'filter_is_active';

	private SecurityUser $securityUser;

	protected function getEntityClass(): string
	{
		return Profile::class;
	}

	protected function init(): void
	{
		parent::init();
		
		$this->filter[self::FILTER_SECURITY] = function (QueryBuilder $qb) {
			if (!$this->securityUser->isAllowed('global.users')) {
			    $qb->andWhere('e.id = :init_id')
			        ->setParameter('id', $this->securityUser->getId())
			}
		};
		
		$this->byIsActive(true);
	}
	
	protected function setDefaultOrder(): void
	{
		$this->orderBy(['identity.lastName' => 'ASC', 'identity.firstName' => 'ASC', 'id' => 'ASC']);
	}
	
	public function byIsActive(bool $isActive): static
	{
		$this->filter[self::FILTER_IS_ACTIVE] = function(QueryBuilder $qb) use ($isActive) {
			$qb->andWhere('e.isActive = :isActive')
				->setParameter('isActive', $isActive);
		};
		
		return $this;
	}
	
	public function byQuery(string $query): static
	{
		$this->by(['identity.firstName', 'identity.lastName', 'identity.email', 'identity.phone'], $query);

		return $this;
	}
	
	public function setSecurityUser(SecurityUser $securityUser): static
	{
	    $this->securityUser = $securityUser;

	    return $this;
	}
}
```

### Method `getEntityClass`

The `getEntityClass` method must be specified and return your entity class.

### Method `setDefaultOrder`

The `setDefaultOrder` method must be specified and set the default order.

### Method `init`

The init method is used to specify default filters and order. You have to always call `parent::init()` when you use it.

### Callback array `filter`

`filter` is array of callbacks which will be applied on `QueryBuilder` when created.

Similarly you can use `order` callback for setting the query object order.

### Method `by` and `orderBy`

Method `by` is a shortcut for creating `filter` callbacks. It offers some useful features:

- When there are more columns, `orWhere` is used among them.

- If a `$value` is type of 'string', `LIKE %$value%` is used. You can change it by parameter `filterType` with value `FilterTypeEnum::STRICT`.

- If you would like get all value in certain range, you can use parameter `filterType` with value `FilterTypeEnum::RANGE`.

Method `orderBy` is a shortcut for setting `order` callback.

- You can use a column name as first parameter and ASC/DESC as a second parameter instead of an array, if you need to sort only by one column.

You can use dot notation to auto join other entities (left join is used).

## Basic usage

### Creating an instance

```php
$queryObject = (new ProfileQueryObject($entityManager))->setSecurityUser($securityUser);
```

or better with the use of a factory:

```php
// example of Nette framework factory
interface ProfileQueryObjectFactory
{
	/** 
	 * Annotation is used for PhpStorm code completion.
	 * 
	 * @return FetchInterface<Profile> 
	 */
	public function create(): ProfileQueryObject;
}
````

together with neon:

```yaml
decorator:
	ADT\DoctrineObjects\QueryObject:
		setup:
			- setEntityManager(@App\Model\Doctrine\EntityManager)
			- setSecurityUser(@security.user)
```

### Fetch results

```php
// returns all active profiles
$profiles = $this->profileQueryObjectFactory->create()->fetch();

// returns all active profiles with name, email or phone containing "Doe"
$profiles = $this->profileQueryObjectFactory->create()->search('Doe')->fetch();

// returns all disabled profiles
$profiles = $this->profileQueryObjectFactory->create()->byIsActive(false)->fetch();

// returns first 10 active profiles
$profiles = $this->profileQueryObjectFactory->create()->fetch(limit: 10);
```

```php
// returns an active profile by ID or throws your own error when a profile does not exist
if (!$profile = $this->profileQueryObjectFactory->create()->byId($id)->fetchOneOrNull()) {
    return new \Exception('Profile not found.');
}

// returns first active profile with name, name, email or phone containing "Doe", "strict: false" has to be specified,
// otherwise NonUniqueResultException may be thrown
$profile = $this->profileQueryObjectFactory->create()->search('Doe')->fetchOneOrNull(strict: false);
```

```php
// returns an active profile by ID or throws NoResultException when profile does not exist
$profile = $this->profileQueryObjectFactory->create()->byId(self::ADMIN_PROFILE_ID)->fetchOne();
```

```php
// returns an active profile as an array of {Profile::getId(): Profile::getName()}
$profiles = $this->profileQueryObjectFactory->create()->fetchPairs('name', 'id');
```

```php
// returns array of active profile ids
$profileIds = $this->profileQueryObjectFactory->create()->fetchField('id');
```

### Count results

```php
// returns number of all active profiles
$numberOfProfiles = $this->profileQueryObjectFactory->create()->count();
```

### Disable default filters

```php
// returns both active and disabled profiles
$profiles = $this->profileQueryObjectFactory->create()->disableFilter(ProfileQueryObject::FILTER_IS_ACTIVE)->fetch();

// returns all profiles without applying a default security filter, for example in console
$profiles = $this->profileQueryObjectFactory->create()->disableFilter(ProfileQueryObject::FILTER_SECURITY)->fetch();

// disable both filters
$profiles = $this->profileQueryObjectFactory->create()->disableFilter([ProfileQueryObject::FILTER_IS_ACTIVE, ProfileQueryObject::FILTER_SECURITY])->fetch();
```

### Pagination

```php
// returns ResultSet, suitable for pagination and for using in templates
$profileResultSet = $this->profileQueryObjectFactory->create()->getResultSet(page: 1, itemsPerPage: 10);

// ResultSet implements IteratorAggregate, so you can use it in foreach
foreach ($profileResultSet as $_profile) {
    echo $_profile->getId();
}

// or call getIterator
$profiles = $profileResultSet->getIterator();

// returns Nette\Utils\Paginator
$paginator = $profileResultSet->getPaginator();

// returns total count of profiles
$numberOfProfiles = $profileResultSet->count();
````

## Advanced features

### Manul joins

For manual joins you should use `innerJoin` and `leftJoin` methods:

```php
public function joinArtificialConsultant(QueryBuilder $qb)
{
	$this->leftJoin($qb, 'e.artificialConsultant', 'e_ac');
}

public function byShowOnWeb(): static
{
	$this->filter[] = function (QueryBuilder $qb) {
		$this->joinArtificialConsultant($qb);
		$qb->andWhere('e.showOnWeb = TRUE OR (e.artificialConsultant IS NOT NULL AND e_ac.showOnWeb = TRUE)');
	};

	return $this;
}
```

Unlike `QueryBuilder::innerJoin` and `QueryBuilder::leftJoin`, this ensures that same joins are not used multiple times and don't throw an error.

### More columns

Don't use `addSelect` inside a `filter` callback. Use `initSelect` method instead:

```php
class OfficeMessageGridQuery extends OfficeMessageQuery
{
	protected function initSelect(QueryBuilder $qb): void
	{
	    parent::initSelect($qb);
	
		$qb->addSelect('e.id');

		$adSub = $qb->getEntityManager()
			->getRepository(Entity\MessageRecipient::class)
			->createQueryBuilder('mr_read')
			->select('COUNT(1)')
			->where('e = mr_read.officeMessage AND mr_read.readAt IS NOT NULL');

		$qb->addSelect('(' . $adSub->getDQL() . ') read');
	}
}
```

Or create your own fetching method:

```php
class IdentityStatisticsQueryObject extends IdentityQueryObject
{
	/**
	 * @return array{'LT25': int, 'BT25ND35': int, 'BT35N45': int, 'BT45N55': int, 'BT55N65': int, 'GT65': int}
	 */
	public function getAgeRange(): array
	{
		$qb = $this->createQueryBuilder(withSelectAndOrder: false);

		$qb->addSelect('
			SUM(CASE WHEN TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) < 25 THEN 1 ELSE 0 END) AS ' . UserEnum::AGE_RANGE_LT25 . ',
			SUM(CASE WHEN TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) >= 25 AND TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) < 35 THEN 1 ELSE 0 END) AS ' . UserEnum::AGE_RANGE_BETWEEN_25N35 . ',
			SUM(CASE WHEN TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) >= 35 AND TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) < 45 THEN 1 ELSE 0 END) AS ' . UserEnum::AGE_RANGE_BETWEEN_35N45 . ',
			SUM(CASE WHEN TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) >= 45 AND TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) < 55 THEN 1 ELSE 0 END) AS ' . UserEnum::AGE_RANGE_BETWEEN_45N55 . ',
			SUM(CASE WHEN TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) >= 55 AND TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) < 65 THEN 1 ELSE 0 END) AS ' . UserEnum::AGE_RANGE_BETWEEN_55N65 . ',
			SUM(CASE WHEN TIMESTAMPDIFF(YEAR, ei.birthDate, CURRENT_DATE()) > 65 THEN 1 ELSE 0 END) AS ' . UserEnum::AGE_RANGE_GT_65 . '
		');

		return $this->getQuery($qb)->getSingleResult();
	}
}
```

When there are more columns specified, default `fetch*` method won't work.

### More complex sorting

You can create your own sorting callback instead of using `orderBy` method:

```php
public function orderByClosestDistance($customerLongitude, $customerLatitude): static
{
	$this->order = function (QueryBuilder $qb) use ($customerLongitude, $customerLatitude) {
		$qb->addSelect('
				( 6373 * acos( cos( radians(:obcd_latitude) ) *
				cos( radians( e.latitude ) ) *
				cos( radians( e.longitude ) -
				radians(:obcd_longitude) ) +
				sin( radians(:obcd_latitude) ) *
				sin( radians( e.latitude ) ) ) )
				AS HIDDEN distance'
			)
			->addOrderBy('distance', 'ASC')
			->setParameter('obcd_latitude', $customerLatitude)
			->setParameter('obcd_longitude', $customerLongitude);
	};

	return $this;
}
```

Don't forget to use `AS HIDDEN` in your `addSelect` method, otherwise the `fetch*` methods won't work.

### Fulltext search

For a fulltext search over a column with a MySQL `FULLTEXT` index implement `SearchFulltextFilter` and use `SearchFulltextFilterTrait`:

```php
use ADT\DoctrineComponents\QueryObject\Filters\SearchFulltextFilter;
use ADT\DoctrineComponents\QueryObject\Filters\SearchFulltextFilterTrait;

class LocationQuery extends QueryObject implements SearchFulltextFilter
{
	use SearchFulltextFilterTrait;

	public function byQuery(string $query): static
	{
		return $this->searchFulltext('searchString', $query);
	}
}
```

It generates `MATCH ... AGAINST (... IN BOOLEAN MODE) > 0`, so the `match_against` DQL function has to be registered in your application (the package doesn't do it for you):

```neon
nettrine.orm:
	configuration:
		customStringFunctions:
			match_against: DoctrineExtensions\Query\Mysql\MatchAgainst
```

The value is split into words on every non-alphanumeric character (so boolean mode operators can't break the query), all words have to match (`AND`) and each of them matches from the beginning of an indexed word - `Ariola Martin` becomes `+Ariola* +Martin*`. Searching inside a word (`rtin`) never matches, that's how a FULLTEXT index works.

Words shorter than `innodb_ft_min_token_size` are not in the index at all, those are searched with `LIKE '%word%'` instead, otherwise a two letter code would never be found. The default is 3 (MySQL InnoDB default), override it if your server is configured differently:

```php
protected function getFulltextMinWordLength(): int
{
	return 4;
}
```

Diacritics are handled by the collation of the column, use an accent insensitive one (eg. `utf8mb4_0900_ai_ci`).

A column path over relations works as well, the join is added automatically:

```php
$this->searchFulltext('location.searchString', $query);
```

### Method `orById`

If you want to get all active records plus a specific one, you can use `orById` method to bypass default filters:

```php
$profiles = $this->profileQueryObjectFactory->create()->orById($id)->fetch();
```

It's especially useful for `<select>`.

### Use in batch processing

If you want to iterate large number of records, you can use https://github.com/Ocramius/DoctrineBatchUtils together with query object: 

```php
$em = EntityManager::create($this->connection, $this->config);

$profile = SimpleBatchIteratorAggregate::fromTraversableResult(
	$this->profileQueryObjectFactory->create()->setEntityManager($em)->fetchIterable(),
	$em,
	100 // flush/clear after 100 iterations
);

foreach ($profiles as $_profile) {
	
}
```

You should always use new `EntityManager` instance, not the default one (because of `EntityManager::clear`).

### Default collections order by `id`

If you want all collection associations (`OneToMany`, `ManyToMany`) to be automatically ordered by the primary key `id` (ASC), you can use the built-in Doctrine subscriber `DefaultOrderByIdSubscriber`.
It hooks into the metadata loading process and applies `ORDER BY id ASC` to every collection that does not already define its own ordering.
This ensures stable and deterministic ordering across your application without having to manually specify `orderBy` in every entity.

Simply enable the subscriber in your neon configuration:

```yaml
services:
	- ADT\DoctrineComponents\Subscribers\DefaultOrderByIdSubscriber
```


## Tips

- Always have all logic inside a `filter` or `order` callback. This will ensure that all dependencies (like a logged user etc.) are already set.

- Always use `and*` methods (`andWhere`, `andSelect`, ...) on QueryBuilder (instead of `where`, `select`, ...).

- Don't use `QueryBuilder::resetDQLPart` method, because it's againt basic idea of QueryObject

- Parameters in `andWhere` method should by named by method name and parametr name to avoid collision.

- Methods `by` and `orderBy` are public methods, but it's always better to create own `by*` or `orderBy*` methods.

- You should always specify a deterministic order, ideally with usage of primary key.

## Session time zone

Sets the application's time zone on a connection right after it opens. Typically for log
storage: logs are written in UTC into `TIMESTAMPTZ` columns, and without this the database
returns them in the server's zone (usually UTC) - the administration would show times
shifted by the zone offset. With the session zone set, the database returns the same
instant with the application's offset, including the correct DST offset for each row.
Stored values are not changed, only how they are read.

```neon
nettrine.dbal:
	connections:
		logdb:
			middlewares:
				timeZone: ADT\DoctrineComponents\Middleware\SessionTimeZoneMiddleware(%timeZone%)
```

Works on PostgreSQL (`SET TIME ZONE`) and MySQL (`SET time_zone`). On MySQL it affects only
`TIMESTAMP` columns, and named zones such as `Europe/Prague` need the time zone tables loaded
(`mysql_tzinfo_to_sql`); an offset such as `+01:00` always works.

## Killing sessions before migrations

`dbal:kill-sessions` kills the other sessions of the connection's database user. A deploy runs
it in a maintenance window right before the migrations (recipe of
[adt/docker-deployer](https://github.com/AppsDevTeam/docker-deployer), target
`migrations-kill-sessions`): DDL on a Galera cluster conflicts with the metadata locks held by
the running application - even a plain `SELECT` in an open transaction holds one - and
an `ALTER TABLE ... ADD FOREIGN KEY` on a table in use can crash a node.

```neon
services:
	- ADT\DoctrineComponents\Console\KillSessionsCommand
```

- `--wait=N` first waits up to N seconds until no session of the user is busy (a running
  statement or an open transaction), so that a consumer finishes its job instead of losing it
  half-way; then the idle sessions are killed. Without the `PROCESS` privilege open transactions
  are not visible (`information_schema.INNODB_TRX`) and only running statements are waited for.
- `KILL` sent through ProxySQL does not reach the backend, so on Galera the command connects
  directly to the node the proxy writes to (`@@wsrep_node_address` + `@@port`, asked through
  the proxy) with the same credentials. Without Galera it uses the connection itself.
- Only the sessions of the same user are killed, which needs no extra privilege. Sessions of
  other users (people with their own accounts, backups) stay.
- Killed in three rounds a second apart: ProxySQL retries a killed autocommit statement on
  a new connection (`mysql-query_retries_on_failure`), which would hold the locks again.

## Indexes on traits

Doctrine reads `#[ORM\Index]` and `#[ORM\UniqueConstraint]` only from the entity class itself
and silently ignores them on traits. An index belongs to its columns, though, and the columns
come from the trait - with `TraitIndexListener` the trait carries its indexes along and the
entity does not have to repeat them:

```php
#[ORM\Index(fields: ['createdAt'])]
trait AuditLogTrait
{
	#[ORM\Column]
	protected DateTimeImmutable $createdAt;
}
```

```neon
extensions:
	doctrineComponents: ADT\DoctrineComponents\DI\DoctrineComponentsExtension
```

The extension registers the listener; nettrine/orm picks it up by type, so the order of
extensions does not matter.

- Traits of the entity, of its parents (mapped superclasses included) and traits used inside
  traits are all searched.
- An index the entity declares itself - with the same name, or on the same columns in the same
  order - is not added again, so the entity can override a trait index with its own definition.
- Unnamed indexes are named by the SchemaTool from the table and columns, so one trait on several
  entities does not collide, and moving an index from the entity to the trait keeps its name in
  the database (`migrations-diff` stays empty).
- A field the entity does not map (or a composite association) fails loudly with
  a `MappingException` naming the trait.
- Indexes are computed when metadata is loaded and cached with it; there is no runtime cost.

Put on traits only what belongs to the trait's columns in every project. Constraints that
depend on the project (e.g. uniqueness per account) stay on the entity.

## Orphan removal safeguards

Two listeners that keep a flush from failing after `orphanRemoval: true` (typically with
Gedmo SoftDeleteable). `DoctrineComponentsExtension` registers both; without the extension
register them as services yourself (nettrine/orm picks them up by type).

### `OrphanRemovalNestedFlushListener`

Doctrine removes orphans in `UnitOfWork::commit()` but clears its list of orphans only after
`postFlush`. A `postFlush` listener that flushes again therefore removes the same orphans
a second time - a soft-deleted entity has already been detached by Gedmo, so the nested flush
fails with *"Detached entity … cannot be removed"* and the whole save is rolled back.

The listener remembers entities passed to `remove()` and cancels their scheduled orphan removal
at the start of the next flush (`preFlush`). The order of listeners does not matter.

It calls the internal `UnitOfWork::cancelOrphanRemoval()` - check it (and the tests) when
upgrading doctrine/orm.

### `MissingEntityRemovalListener`

`remove()` of an entity whose row no longer exists is a no-op instead of
an `EntityNotFoundException`. Typically an association holds an uninitialized proxy whose row
was meanwhile deleted by a concurrent request. Doctrine itself does not load the proxy on
`remove()`, but listeners do - Gedmo SoftDeleteable writes `deletedAt` in `onFlush` - and
loading it fails the flush.

The listener loads the proxy in `preRemove`. When that fails:

- **the row does not exist** (checked by a plain query, without SQL filters) - the entity is
  detached, so it is never scheduled for deletion;
- **the row is hidden by a soft-delete filter** listed in `softDeleteFilters` (a reference to an
  already soft-deleted row) - the entity is loaded with that filter suspended and detached;
  the row stays soft-deleted;
- **the row is hidden by any other filter** (e.g. tenant) - nothing changes, the removal runs
  as without the listener.

```neon
doctrineComponents:
	softDeleteFilters: [soft-deleteable]
```

Without the extension register it as `MissingEntityRemovalListener(['soft-deleteable'])`.

- Removing an **uninitialized proxy** costs one extra `SELECT` (the proxy is loaded); when it
  cannot be loaded, one more query checks whether the row exists. Initialized entities are untouched.
- Soft-delete filters are suspended via `FilterCollection::suspend()`/`restore()`, which keeps
  their parameters; on an ORM without `suspend()` the soft-delete case is not handled.
- Only single-column identifiers are checked for existence; with a composite identifier the
  listener does not interfere.
- Detaching does not stop other `preRemove` listeners of the same event - a listener that reads
  data of the entity in `preRemove` should skip uninitialized proxies
  (`$em->isUninitializedObject($entity)`), or be registered after this listener.

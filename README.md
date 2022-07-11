# Query Object

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
			if (!$this->securityUser->isAdmin()) {
			    $qb->andWhere('e.id = :init_id')
			        ->setParameter('id', $this->securityUser->getId())
			}
		};
		
		$this->byIsActive(true);
		
		$this->orderBy(['identity.lastName' => 'ASC', 'identity.firstName' => 'ASC']);
	}
	
	public function byIsActive(bool $isActive): static
	{
		$this->filter[self::FILTER_IS_ACTIVE] = function(QueryBuilder $qb) use ($isActive) {
			$qb->andWhere('e.isActive = :isActive')
				->setParameter('isActive', $isActive);
		};
		
		return $this;
	}
	
	public function search(string $value)
	{
		$this->by(['identity.firstName', 'identity.lastName', 'identity.email', 'identity.phone'], $value);

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

### Method `init`

The init method is used to specify default filters and order. You have to always call `parent::init()` when you use it.

### Callback array `filter`

`filter` is array of callbacks which will be applied on `QueryBuilder` when created.

### Method `by`

Method `by` is a shortcut for creating `filter` callbacks.

When there are more columns, `orWhere` is used among them.

If a `$value` is type of 'string' `LIKE %$value%` is used. You can change it by `filterType: FilterTypeEnum::STRICT`.

If you would like get all value in certain range, you can use `filterType: FilterTypeEnum::RANGE`.

You can use dot notation to auto join other entities.

### Method `orderBy`

Method `orderBy` creates a callback which will be applied on `QueryBuilder` when created.

Unlike the `filter` callbacks, only one `order` callback can be specified.

You can use also use column name as first parameter and ASC/DESC as second instead of array.

You can use dot notation to auto join other entities.

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

// returns first 10 profiles
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
// returns array of profile ids
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
$numberOfProfile = $profileResultSet->count();
````

## Advanced usage

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

Unlike `QueryBuilder::innerJoin` and `QueryBuilder::leftJoin`, this ensures that same joins are not used multiple times.

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
		$qb = $this->createQueryBuilder(withSelect: false, withOrder: false);

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

## Tips

- Always have all logic inside a `filter` or `order` callback. This will ensure that all dependencies (like a logged user etc.) are already set.

- Always use `and*` methods (`andWhere`, `andSelect`, ...) on QueryBuilder (instead of `where`, `select`, ...).

- Don't use `QueryBuilder::resetDQLPart` method, because it's againt basic idea of QueryObject

- Parameters in `andWhere` method should by named by method name and parametr name to avoid collision.

- Methods `by` and `orderBy` are public methods, but it's always better to create own `by*` or `orderBy` method.

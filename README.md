# Doctrine Components

## Install

```
composer require adt/doctrine-components
```

## Creating a query object

```php
/**
 * @extends QueryObject<Banner>
 * @implements FetchInterface<Banner>
 */
class UserQueryObject extends QueryObject implements FetchInterface
{
    use FetchTrait;

	const FILTER_SECURITY = 'filter_security';

	private SecurityUser $securityUser;

	protected function getEntityClass(): string
	{
		return User::class;
	}

	protected function init(): void
	{
		parent::init();

		$this->filter[] = function (QueryBuilder $qb) {
			$this->joinIdentity($qb);

			$qb->andWhere('identity.isActive = 1');
		};
		
		$this->filter[self::FILTER_SECURITY] = function (QueryBuilder $qb) {
			if (!$this->securityUser->isAdmin()) {
			    $qb->andWhere('e.id = :securityFilterId')
			        ->setParameter('id', $this->securityUser->getId())
			}
			$qb->andWhere('identity.isActive = 1');
		};

		$this->order = function (QueryBuilder $qb) {
		    $this->joinIdentity($qb);
		
		    $qb->andOrder('identity.lastName', 'ASC');
		}
	}
    
	public function joinIdentity(QueryBuilder $qb)
	{
		$this->innerJoin($qb, 'e.identity', 'identity');
	}
	
	public function disableSecurityFilter()
	{
		unset($this->filter[self::FILTER_SECURITY]);
	}
	
	public function byLastName(string $lastName): static
	{
		$this->filter[] = function (QueryBuilder $qb) {
			$this->joinIdentity($qb);

			$qb->andWhere('identity.lastName = :lastName')
				->setParameter('lastName', $lastName);
		};
		
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

The init method is used to specify default filters and sorting. You have to always call `parent::init()` when you use it.

### Callbacks `filter` and `order`

`filter` is array of callbacks which will be applied when QueryBuilder is created.

Unlike the filter callbacks, only one `order` callback can be specified.

## Basic usage

```php
$users = (new UserQueryObject($entityManager))
	->setSecurityUser($securityUser)
	->byLastName('Doe')
	->fetch();
```

or better with the use of a factory:

```php
// example of Nette framework factory
interface UserQueryObjectFactory
{
	/** @return FetchInterface<User> */
	public function create(): UserQueryObject;
}
````

```php
// returns all users with last name Doe
$users = $this->userQueryObjectFactory->create()->byLastName('Doe')->fetch();
```

```php
// returns number of users with last name Doe
$numberOfUsers = $this->userQueryObjectFactory->create()->byLastName('Doe')->count();
```

```php
// returns first 10 users with last name Doe
$users = $this->userQueryObjectFactory->create()->byLastName('Doe')->fetch(limit: 10);
```

```php
// returns ResultSet, suitable for pagination and for using in templates
$userResultSet = $this->userQueryObjectFactory->create()->byLastName('Doe')->getResultSet(page: 1, itemsPerPage: 10);

// ResultSet implements IteratorAggregate, so you can use it in foreach
foreach ($userResultSet as $_user) {
    echo $_user->getId();
}

// or call getIterator
$users = $userResultSet->getIterator();

// returns Nette\Utils\Paginator
$paginator = $userResultSet->getPaginator();

// returns total count of users
$numberOfUsers = $userResultSet->count();
````

```php
// returns a user by ID or throws your own error when user does not exist
if (!$user = $this->userQueryObjectFactory->create()->byId($id)->fetchOneOrNull()) {
    return new \Exception('User not found.');
}
```

```php
// returns a user by ID or throws NoResultException when user does not exist
$user = $this->userQueryObjectFactory->create()->byId(self::ADMIN_USER_ID)->fetchOne();
```

```php
// returns first user with last name Doe, strict: false has to be specified,
// otherwise NonUniqueResultException may be thrown
$user = $this->userQueryObjectFactory->create()->byLastName('Doe')->fetchOneOrNull(strict: false);
```

```php
// disabling default security filter, for example in console
$users = $this->userQueryObjectFactory->create()->disableSecurityFilter()->byLastName('Doe')->fetch(limit: 10);
```

```php
// returns users as an array of {User::getId(): User::getName()}
$users = $this->userQueryObjectFactory->create()->fetchPairs('name', 'id');
```

## Tips

- Always have all logic inside a `filter` or `order` callback. This will ensure that all dependencies are already set.

- Don't use `addSelect` or `addOrder` inside a `filter` callback.

- If you need `addSelect` in your `order` callback, be sure to mark it `AS HIDDEN`.

- Don't use `leftJoin` or `innerJoin` on QueryBuilder, use `QueryObject::leftJoin` or `QueryObject::innerJoin` instead. This ensures that same joins are not used multiple times.

- Always use `and*` methods (`andWhere`, `andSelect`, ...) on QueryBuilder (instead of `where`, `select`, ...).

## Advanced usage

### Method `initSelect`

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

Thanks to `initSelect`, we can add more columns to the result. 

When using `initSelect`, only `getResultSet` or own method is allowed to use for fetching results (you can't use default `fetch*` methods).

### Own fetching methods

When you need to specify own columns, you have to create own fetching method:

```php
class UserStatisticsQuery extends UserQuery
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

### Use in batch processing

When you want to iterate large number of records, you can use https://github.com/Ocramius/DoctrineBatchUtils together with query object: 

```php
$em = \Doctrine\ORM\EntityManager::create($this->connection, $this->config);

$users = SimpleBatchIteratorAggregate::fromTraversableResult(
	$this->userQueryFactory->create()->setEntityManager($em)->fetchIterable(),
	$em,
	100 // flush/clear after 100 iterations
);

foreach ($users as $_user) {
	
}
```

You should always use own EntityManager instance, not the default one (because of entity manager clearing).

## Using `searchIn` and `*Join`

Entities used in examples.

* Library
  * Book
    * Author

Examples are in `LibraryQuery`


### Simple

Joins are done automatically when using `searchIn`

    public function byAuthorName(string $name) {
        $this->searchIn('books.author.name', $name);
    }


### Own joins

Use `BaseQuery::leftJoin` and `BaseQuery::innerJoin` to join with extended conditions and with simple filtering.

    public function byAuthorName(string $name) {
        $this->leftJoin('books', 'book', '...', '...');
        $this->innerJoin('book.author', author, '...', '...');
        $this->searchIn('books.author.name', $name);
    }


### Own filter

Use automatic joins and a custom extended filter

    public function byAuthorIsAdult(int $age = 18) {
        $this->addJoins('books.author');
        $this->filter[] = function (QueryBuilder $qb) use ($age) {
            $qb->andWhere('author >= :age', $age)
                ->setParameter('age', $age);
            };
        };
        return $this;
    }


### Own join and filter

Use `QueryObject::leftJoin` and `QueryObject::leftJoin` and a custom filter.

    public function byAuthorIsAdult(int $age = 18) {
        $this->leftJoin('....');
        $this->innerJoin('....');
        $this->filter[] = function (QueryBuilder $qb) use ($age) {
            ....
        };
        return $this;
    }

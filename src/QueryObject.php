<?php

namespace ADT\DoctrineComponents;

use ArrayIterator;
use Closure;
use Doctrine;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Generator;
use Iterator;
use Nette\Utils\Arrays;
use Nette\Utils\Strings;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

/**
 * @template TEntity of object
 */
abstract class QueryObject implements QueryObjectInterface
{
	const JOIN_INNER = 'innerJoin';
	const JOIN_LEFT = 'leftJoin';

	protected array $orByIdFilter = [];

	protected ?array $byIdFilter = null;

	protected string $entityAlias = 'e';

	/** @var Closure[] */
	protected array $filter = [];

	protected ?Closure $order = null;

	protected array $hints = [];

	protected array $postFetch = [];

	protected ?EntityManagerInterface $em = null;

	private array $join = [];

	private bool $isInitialized = false;

	abstract protected function getEntityClass(): string;
	abstract protected function setDefaultOrder(): void;

	/**
	 * @throws Exception
	 */
	final public function __construct(EntityManagerInterface $em)
	{
		$this->em = $em;

		$this->init();
		if (!$this->isInitialized) {
			throw new Exception('Always call "parent::init()" when overriding the "init" method.');
		}

		$this->setDefaultOrder();
	}

	final public function getEntityManager(): ?EntityManagerInterface
	{
		return $this->em;
	}

	final public function setEntityManager(EntityManagerInterface $em): static
	{
		$this->em = $em;
		return $this;
	}

	protected function init(): void
	{
		$this->isInitialized = true;
	}

	protected function initSelect(QueryBuilder $qb): void
	{
		$qb->select($this->entityAlias);
	}

	/*********************
	 * FILTERS AND ORDER *
	 *********************/

	/**
	 * @param int|int[]|IEntity|IEntity[]|[]|null $id
	 * @return static
	 */
	public function byId($id): static
	{
		if (is_iterable($id) && !is_string($id)) {
			foreach ($id as $item) {
				if (is_object($item)) {
					$this->byIdFilter[$item->getId()] = $item->getId();
				}
				else {
					$this->byIdFilter[$item] = $item;
				}
			}

			//If we did not fill anything, we want to set an empty array to set the 'id IN (NULL)' in the resulting filters
			if (count($id) === 0) {
				$this->byIdFilter = [];
			}
		}
		elseif (is_object($id)) {
			$this->byIdFilter[$id->getId()] = $id->getId();
		}
		//we still want to add 'id IN (null)' if we pass $id=null
		elseif ($id === null) {
			$this->byIdFilter = [];
		}
		else {
			$this->byIdFilter[$id] = $id;
		}

		return $this;
	}

	/**
	 * @param int|int[]|IEntity|IEntity[] $id
	 */
	final public function orById($id): static
	{
		if (is_iterable($id) && !is_string($id)) {
			foreach ($id as $item) {
				if (is_object($item)) {
					$this->orByIdFilter[$item->getId()] = $item->getId();
				}
				else {
					$this->orByIdFilter[$item] = $item;
				}
			}
		}
		elseif (is_object($id)) {
			$this->orByIdFilter[$id->getId()] = $id->getId();
		}
		else {
			$this->orByIdFilter[$id] = $id;
		}

		return $this;
	}

	final public function disableFilter(array|string $filter): static
	{
		foreach ((array) $filter as $_filter) {
			unset($this->filter[$_filter]);
		}
		
		return $this;
	}

	final public function disableDefaultOrder(): static
	{
		$this->order = null;
		
		return $this;
	}

	/**
	 * Obecná metoda na vyhledávání ve více sloupcích (spojení přes OR).
	 * Operátor lze měnit pomocí QueryObjectByMode $mode, výchozí je STRICT (equal)
	 *
	 * @param string|string[] $column
	 * @param mixed $value
	 * @param QueryObjectByMode $mode
	 * @return $this
	 */
	final public function by(array|string $column, mixed $value, QueryObjectByMode $mode = QueryObjectByMode::STRICT): static
	{
		$this->filter[] = function (QueryBuilder $qb) use ($column, $value, $mode) {
			$column = (array) $column;

			$this->validateFieldNames($column);

			$this->addJoins($qb, $column);

			$x = array_map(
				function($_column) use ($qb, $value, $mode) {
					$paramName = 'by_' . str_replace('.', '_', $_column);

					// Pro between chceme rozdelit value do dvou různých podmínek
					if ($mode === QueryObjectByMode::BETWEEN || $mode === QueryObjectByMode::NOT_BETWEEN) {
						$paramName2 = 'by_' . str_replace('.', '_', $_column) . '_2';
					}

					$_column = $this->addColumnPrefix($_column);
					$_column = $this->getJoinedEntityColumnName($_column);

					if (is_null($value)) {
						$mode = QueryObjectByMode::IS_EMPTY;
					} else if (is_array($value) && $mode === QueryObjectByMode::STRICT) {
						$mode = QueryObjectByMode::IN_ARRAY;
					}

					$condition = "$_column = :$paramName";

					switch ($mode) {
						case QueryObjectByMode::STRICT:
							$condition = "$_column = :$paramName";
							break;

						case QueryObjectByMode::NOT_EQUAL:
							$condition = "$_column != :$paramName";
							break;

						case QueryObjectByMode::STARTS_WITH:
							$value = "$value%";
							$condition = "$_column LIKE :$paramName";
							break;

						case QueryObjectByMode::ENDS_WITH:
							$value = "%$value";
							$condition = "$_column LIKE :$paramName";
							break;

						case QueryObjectByMode::CONTAINS:
							$value = "%$value%";
							$condition = "$_column LIKE :$paramName";
							break;

						case QueryObjectByMode::NOT_CONTAINS:
							$value = "%$value%";
							$condition = "$_column NOT LIKE :$paramName";
							break;

						case QueryObjectByMode::IS_EMPTY:
							$value = null;
							$condition = "$_column IS NULL";
							break;

						case QueryObjectByMode::IS_NOT_EMPTY:
							$value = null;
							$condition = "$_column IS NOT NULL";
							break;

						case QueryObjectByMode::IN_ARRAY:
							$condition = "$_column IN (:$paramName)";
							break;

						case QueryObjectByMode::NOT_IN_ARRAY:
							$condition = "$_column NOT IN (:$paramName)";
							break;

						case QueryObjectByMode::GREATER:
							$condition = "$_column > :$paramName";
							break;

						case QueryObjectByMode::GREATER_OR_EQUAL:
							$condition = "$_column >= :$paramName";
							break;

						case QueryObjectByMode::LESS:
							$condition = "$_column < :$paramName";
							break;

						case QueryObjectByMode::LESS_OR_EQUAL:
							$condition = "$_column <= :$paramName";
							break;

						case QueryObjectByMode::BETWEEN:
							$condition = "$_column BETWEEN :$paramName AND :$paramName2";
							break;

						case QueryObjectByMode::NOT_BETWEEN:
							$condition = "$_column NOT BETWEEN :$paramName AND :$paramName2";
							break;
					}

					if ($mode === QueryObjectByMode::BETWEEN || $mode === QueryObjectByMode::NOT_BETWEEN) {
						$qb->setParameter($paramName, $value[0]);
						$qb->setParameter($paramName2, $value[1]);
					} else if (!is_null($value)) {
						$qb->setParameter($paramName, $value);
					}

					return $condition;
				},
				$column
			);
			$qb->andWhere($qb->expr()->orX(...$x));
		};
		return $this;
	}

	/**
	 * @param array{string: string}|string $field
	 * @param string|null $order
	 * @return $this
	 */
	final public function orderBy(array|string $field, ?string $order = null): static
	{
		$this->order = function (QueryBuilder $qb) use ($field, $order) {
			if (is_string($field)) {
				$field = [$field => $order];
			} elseif ($order) {
				throw new \Exception ('Do not specify "$order" if "$field" is an array.');
			}

			if (empty($field)) {
				throw new Exception('Parameter "$field" cannot be empty.');
			}

			$this->validateFieldNames($field);

			$this->addJoins($qb, array_keys($field));

			$qb->resetDQLPart('orderBy');
			foreach ($field as $_name => $_order) {
				$_name = $this->addColumnPrefix($_name);
				$_name = $this->getJoinedEntityColumnName($_name);

				$qb->addOrderBy($_name, $_order);
			}
		};

		return $this;
	}

	/** @internal */
	final protected function validateFieldNames(array $fields): void
	{
		foreach ($fields as $_name => $_order) {
			if (explode('.', $_name)[0] === $this->entityAlias) {
				throw new \Exception('Do not use entity alias in field names.');
			}
		}
	}

	/***************************
	 * QUERY BUILDER AND QUERY *
	 ***************************/

	final public function getQuery(?QueryBuilder $qb = null): Doctrine\ORM\Query
	{
		$query = ($qb ?: $this->createQueryBuilder())->getQuery();

		foreach ($this->hints as $_name => $_value) {
			$query->setHint($_name, $_value);
		}

		return $query;
	}

	/**
	 * @throws Exception
	 */
	final public function createQueryBuilder(bool $withSelectAndOrder = true): QueryBuilder
	{
		$qb = $this->em->createQueryBuilder()->from($this->getEntityClass(), $this->entityAlias);

		$this->join = [];

		// we need to use a reference to allow adding a filter inside another filter
		foreach ($this->filter as &$_filter) {
			$_filter->call($this, $qb);
		}
		unset ($_filter);

		$forbiddenDQLParts = ['select', 'distinct', 'orderBy'];
		foreach ($forbiddenDQLParts as $_forbiddenDQLPart) {
			if ($qb->getDQLPart($_forbiddenDQLPart)) {
				throw new Exception('Modifying "' . $_forbiddenDQLPart . '" DQL part in filters is not allowed.');
			}
		}

		//orById
		if ($this->orByIdFilter && $qb->getDQLPart('where')) {
			$qb->orWhere('e.id IN (:orByIdFilter)')
				->setParameter('orByIdFilter', $this->orByIdFilter);
		}

		//byId
		if ($this->byIdFilter !== null) {
			$qb->andWhere('e.id IN (:byIdFilter)')
				->setParameter('byIdFilter', $this->byIdFilter);
		}

		if ($withSelectAndOrder) {
			$this->initSelect($qb);

			$this->order?->call($this, $qb);
		}

		return $qb;
	}

	/*********
	 * JOINS *
	 *********/

	final protected function leftJoin(QueryBuilder $qb, string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): self
	{
		return $this->commonJoin($qb, __FUNCTION__, $join, $alias, $conditionType, $condition, $indexBy);
	}

	final protected function innerJoin(QueryBuilder $qb, string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): self
	{
		return $this->commonJoin($qb, __FUNCTION__, $join, $alias, $conditionType, $condition, $indexBy);
	}

	/** @internal */
	final protected function addJoins(QueryBuilder $qb, array $columns): void
	{
		$joinType = self::JOIN_LEFT;

		foreach ($columns as $key => $column) {
			// it's not a join
			if (!str_contains($column, '.')) {
				continue;
			}

			$aliasLast = null;
			foreach (explode('.', $column, '-1') as $aliasNew) {
				$join = $aliasLast ? $aliasLast . '.' . $aliasNew : $this->addColumnPrefix($aliasNew);
				$filterKey = $this->getJoinFilterKey($joinType, $join, $aliasNew);
				if (!$this->isAlreadyJoined($filterKey)) {
					$this->commonJoin($qb, $joinType, $join, $aliasNew);
				}
				$aliasLast = $aliasNew;
			}
		}
	}

	/** @internal */
	final protected function addColumnPrefix(?string $column = NULL): string
	{
		if ((!str_contains($column, '.')) && (!str_contains($column, '\\'))) {
			$column = $this->entityAlias . '.' . $column;
		}
		return $column;
	}

	/** @internal */
	final protected function getJoinedEntityColumnName(string $column): string
	{
		return implode('.', array_slice(explode('.', $column), -2));
	}

	private function commonJoin(QueryBuilder $qb, string $joinType, string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): self
	{
		$join = $this->addColumnPrefix($join);
		$filterKey = $this->getJoinFilterKey($join, $alias, $conditionType, $condition, $indexBy);

		if (! $this->isAlreadyJoined($filterKey)) {
			$qb->$joinType($join, $alias, $conditionType, $condition, $indexBy);
			$this->join[$filterKey] = true;
		}

		return $this;
	}

	private function getJoinFilterKey(string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): string
	{
		return implode('_', [$join, $alias, $conditionType, $condition, $indexBy]);
	}

	private function isAlreadyJoined(string $filterKey): bool
	{
		return isset($this->join[$filterKey]);
	}

	/*********
	 * FETCH *
	 *********/

	/**
	 * @return TEntity[]
	 * @throws ReflectionException
	 * @throws Exception
	 */
	final public function fetch(?int $limit = null, ?int $offset = null, bool $lock = false): array
	{
		$qb = $this->createQueryBuilder();

		if ($this->hasModifiedColumns($qb)) {
			throw new Exception('Cannot call ' . __METHOD__ . ' on a query object with modified columns.');
		}

		$query = $this->getQuery($qb);

		if ($limit) {
			$query->setMaxResults($limit);
		}

		if ($offset) {
			$query->setFirstResult($offset);
		}

		if ($lock) {
			$query->setLockMode(LockMode::PESSIMISTIC_WRITE);
		}

		$result = $query->getResult();

		$this->postFetch(new ArrayIterator($result));

		return $result;
	}

	/**
	 * @return TEntity[]
	 * @throws Exception
	 */
	final public function fetchIterable(): Generator
	{
		$qb = $this->createQueryBuilder();

		if ($this->hasModifiedColumns($qb)) {
			throw new Exception('Cannot call ' . __METHOD__ . ' on a query object with modified columns.');
		}

		return $this->getQuery($qb)->toIterable();
	}

	/**
	 * @return TEntity
	 * @throws NoResultException
	 * @throws NonUniqueResultException
	 * @throws ReflectionException
	 */
	final public function fetchOne(bool $strict = true, bool $lock = false): object
	{
		$result = $this->fetch(2, null, $lock);

		if (!$result) {
			throw new NoResultException();
		}

		if ($strict && count($result) > 1) {
			throw new NonUniqueResultException();
		}

		$this->postFetch(new ArrayIterator($result));

		return $result[0];
	}

	/**
	 * @return TEntity
	 * @throws NonUniqueResultException
	 * @throws ReflectionException
	 */
	final public function fetchOneOrNull(bool $strict = true, bool $lock = false): object|null
	{
		try {
			return $this->fetchOne($strict, $lock);
		} catch (NoResultException) {
			return null;
		}
	}

	/**
	 * @throws Exception
	 */
	public function fetchPairs(?string $value, ?string $key): array
	{
		$items = [];
		foreach ($this->fetch() as $item) {
			$_key = $item->{'get' . ucfirst($key)}();
			if (!is_scalar($_key)) {
				throw new Exception('The key must not be of type `' . gettype($_key) . '`.');
			}

			$items[$_key] = $value ? $item->{'get' . ucfirst($value)}() : $item;
		}

		return $items;
	}

	/**
	 * @throws Exception
	 */
	public function fetchField(string $field): array
	{
		$qb = $this->createQueryBuilder(false);

		if ($this->hasModifiedColumns($qb)) {
			throw new Exception('Cannot call fetchField on a query object with modified columns.');
		}

		$identifierFieldName = $this->em->getClassMetadata($this->getEntityClass())->getIdentifierFieldNames()[0];
		if ($field === $identifierFieldName) {
			$qb->select('e.' . $field . ' AS field');
		} else {
			$qb->select('IDENTITY(e.' . $field . ') AS field')
				->groupBy('e.' . $field);
		}

		$query = $this->getQuery($qb);

		$items = [];
		foreach ($query->getResult(AbstractQuery::HYDRATE_SCALAR) as $item) {
			$items[$item['field']] = $item['field'];
		}

		return $items;
	}

	/**
	 * @throws Doctrine\ORM\NonUniqueResultException
	 * @throws NoResultException
	 * @throws Exception
	 */
	final public function count(): int
	{
		$qb = $this->createQueryBuilder(false);

		$qb->select('COUNT(' . $this->getCountExpr() . ')');

		if ($qb->getDQLPart('groupBy')) {
			$paginator = new Doctrine\ORM\Tools\Pagination\Paginator($qb);
			return $paginator->count();
		}

		$query = $this->getQuery($qb);

		return (int) $query->getSingleScalarResult();
	}
	
	protected function getCountExpr(): string
	{
		return $this->entityAlias . '.id';
	}

	final public function getResultSet(int $page, int $itemsPerPage): ResultSet
	{
		return new ResultSet($this, $page, $itemsPerPage);
	}


	private function hasModifiedColumns(QueryBuilder $qb): bool
	{
		/** @var Doctrine\ORM\Query\Expr\Select $_selectDQL */
		foreach ($qb->getDQLPart('select') as $_selectDQL) {
			foreach ($_selectDQL->getParts() as $_select) {
				if ($_select !== $this->entityAlias && stristr($_select, 'AS HIDDEN') === false) {
					return true;
				}
			}
		}

		return false;
	}

	/**************
	 * POST FETCH *
	 **************/

	/**
	 * @param EntityManagerInterface $em
	 * @param IEntity[] $rootEntities Jeden typ entit, např. 10x User.
	 * @param string[] $fieldNames Názvy relací v hlavní entitě. Pro zanoření použij '.'. Např. [ 'address' ].
	 * @throws ReflectionException
	 * @throws Exception
	 */
	public static function doPostFetch(EntityManagerInterface $em, array $rootEntities, array $fieldNames): void
	{
		if (empty($rootEntities)) {
			return;
		}

		$currentFieldNames = [];
		$childrenFieldNames = []; // fieldName => childrenFieldNames

		foreach ($fieldNames as $fieldName) {
			if (!Strings::contains($fieldName, '.')) {
				// fetch jen pro toto pole
				$currentFieldNames[] = $fieldName;
			} else {
				// fetch do hloubky
				list($fieldName, $childFieldName) = explode('.', $fieldName, 2);
				// nejdřív fetchneme nejbližší entitu
				$currentFieldNames[] = $fieldName;
				// potom její potomky
				$childrenFieldNames[$fieldName][] = $childFieldName;
			}
		}

		// nefetchovat víckrát stejné entity
		$fieldNames = array_unique($currentFieldNames);

		// díky první rootovské entitě víme, co se vlastně selectuje
		$firstRootEntity = $rootEntities[0];

		if (!is_object($firstRootEntity) && isset($firstRootEntity[0]) && is_object($firstRootEntity[0])) {
			// entita je schovaná v ArrayResultu
			$rootEntities = Arrays::associate($rootEntities, '[]=0');
			$firstRootEntity = $rootEntities[0];
		}

		if (!is_object($firstRootEntity) || !($firstRootEntity instanceof IEntity)) {
			// a není to entita, rychle pryč
			return;
		}

		// posbíráme ID rootovských entit, např. ID Userů
		$rootIds = [];
		foreach ($rootEntities as $rootEntity) {
			$rootIds[] = $rootEntity->getId();
		}

		// budeme potřebovat data o asociacích z Doctriny
		$rootEntityAssociations = $em->getClassMetadata(get_class($firstRootEntity))->associationMappings;

		// vyfiltrujeme neexistující fieldNames
		$availableFieldNames = array_keys($rootEntityAssociations);
		foreach ($fieldNames as $fieldName) {
			if (!in_array($fieldName, $availableFieldNames)) {
				throw new Exception("PostFetch: Entita '". get_class($firstRootEntity) ."' nemá pole '$fieldName'.");
			}
		}
		$fieldNames = array_intersect($availableFieldNames, $fieldNames);

		// připravíme QueryBuilder pro vytažení IDček *_TO_ONE asociací, např. z Userů
		$qb = $em->getRepository(get_class($firstRootEntity))->createQueryBuilder('e')
			->select('PARTIAL e.{id} AS e_id')
			->andWhere('e.id IN (:ids)')
			->setParameter('ids', $rootIds);

		// budeme si je počítat, abychom nedělali prázdný dotaz
		$toOneAssociations = 0;

		foreach ($fieldNames as $i => $fieldName) {
			// $fieldName je např. 'address'
			$association = $rootEntityAssociations[$fieldName];

			if ($association['type'] & Doctrine\ORM\Mapping\ClassMetadataInfo::TO_ONE) {
				// pokud je asociace *_TO_ONE, tak přidáme select na její ID a zajistíme provedení dotazu

				$qb->addSelect('IDENTITY(e.' . $fieldName . ') AS id_' . $i);
				$toOneAssociations++;
			}
		}

		if ($toOneAssociations > 0) {
			// pokud alespoň jedna TO_ONE asociace, provedeme dotaz a zjistíme ID všech připojených entit
			$foreignKeysInRootEntities = $qb
				->getQuery()
				->getScalarResult();
		}

		foreach ($fieldNames as $i => $fieldName) {
			// pro jednotlivé vazby v hlavní entitě, např 'address'

			// metadata vazby, např. z Usera na adresu
			$association = $rootEntityAssociations[$fieldName];

			// $propertyName je název sloupce z druhé strany, např. Address#user
			$propertyName = $association['mappedBy'] ?: $association['inversedBy'];

			if ($propertyName === NULL) {
				throw new Exception("PostFetch rootEntity='{$association['sourceEntity']}', targetEntity='{$association['targetEntity']}': Nelze přiřadit entity k root entitě. Chybí mappedBy nebo inversedBy.");
			}

			// pro každou asociaci (např. 'address') si připravíme QueryBuilder
			$qb = $em->createQueryBuilder()
				->select('e')
				->from($association['targetEntity'], 'e');

			if ($association['type'] & Doctrine\ORM\Mapping\ClassMetadataInfo::TO_ONE) {
				// pokud se jedná a TO_ONE asociaci, posbíráme IDčka připojených entit
				// např. u Usera je jen jedna adresa

				$ids = [];
				foreach ($foreignKeysInRootEntities as $row) {
					$id = $row['id_' . $i];

					if ($id) {
						$ids[] = $id;
					}
				}

				// pozor na prázdný dotaz
				if (empty($ids)) {
					continue;
				}

				// a přidáme podmínku
				$qb
					->orWhere('e.id IN (:ids)')
					->setParameter('ids', array_unique($ids));
			} elseif ($association['type'] === Doctrine\ORM\Mapping\ClassMetadataInfo::ONE_TO_MANY) {
				// u ONE_TO_MANY asociací stačí selectovat podle IDček rootovských entit
				// např. jeden User má více adres, v adrese je nastaven User
				$qb
					->orWhere('e.' . $association['mappedBy'] . ' IN (:ids)')
					->setParameter('ids', array_unique($rootIds));
			} elseif ($association['type'] === Doctrine\ORM\Mapping\ClassMetadataInfo::MANY_TO_MANY) {
				// u MANY_TO_MANY asociací musíme (např. adresu) joinovat s root entitou (User) a pak selectovat podle IDček rootovských entit
				$qb
					->leftJoin('e.' . $propertyName, $propertyName)
					->orWhere($propertyName . '.id IN (:ids)')
					->setParameter('ids', array_unique($rootIds));
			} else {
				continue;
			}

			// provedeme select (např. adres)
			$result = $qb
				->getQuery()
				->getResult();

			// v pripadne TO_ONE nám Doctrine entity přiřadí
			// musime tedy poresit jen TO_MANY
			if ($association['type'] & Doctrine\ORM\Mapping\ClassMetadataInfo::TO_MANY) {
				$refCollProperty = new ReflectionProperty(get_class($firstRootEntity), $association['fieldName']);
				$refCollProperty->setAccessible(true);

				$refInitProperty = new ReflectionProperty(PersistentCollection::class, 'initialized');
				$refInitProperty->setAccessible(true);

				if ($association['type'] === Doctrine\ORM\Mapping\ClassMetadataInfo::MANY_TO_MANY) {
					// u MANY_TO_MANY relací se nám ztratila informace o tom, která entita patří do jaké kolekce,
					// dalším dotazem tedy zjistíme co kam máme dát

					$manyToManyMapping = $em->createQueryBuilder()
						->from($association['targetEntity'], 'e')
						->select('e.id AS childEntityId, ' . $propertyName . '.id AS rootEntityId')
						->leftJoin('e.' . $propertyName, $propertyName)
						->andWhere($propertyName . '.id IN (:ids)')
						->setParameter('ids', array_unique($rootIds))
						->getQuery()
						->getArrayResult();
				}

				// přiřadíme výsledky tam, kam patří
				foreach ($result as $row) {
					$collections = [];

					if ($association['type'] !== Doctrine\ORM\Mapping\ClassMetadataInfo::MANY_TO_MANY) {
						$reflector = new ReflectionClass($row);
						$property = $reflector->getProperty($propertyName);
						$property->setAccessible(true);
						$rootEntity = $property->getValue($row);
						$collections[] = $refCollProperty->getValue($rootEntity);
					} elseif (isset($manyToManyMapping)) {
						foreach ($manyToManyMapping as $mapping) {
							if ($mapping['childEntityId'] !== $row->getId()) {
								continue;
							}

							$rootEntityIdx = array_search($mapping['rootEntityId'], $rootIds);

							if ($rootEntityIdx !== FALSE) {
								$rootEntity = $rootEntities[$rootEntityIdx];
								$collections[] = $refCollProperty->getValue($rootEntity);
							}
						}
					}

					foreach ($collections as $collection) {
						if ($collection instanceof PersistentCollection) {
							if ($refInitProperty->getValue($collection)) {
								// kolekce už je inicializovaná
								continue;
							}

							$collection->hydrateAdd($row);
						}
					}
				}

				// a nastavíme kolekci jako inicializovanou, to zabrání Doctrině znovu
				// selectovat data, která už tam jsou
				foreach ($rootEntities as $rootEntity) {
					$collection = $refCollProperty->getValue($rootEntity);
					if ($collection instanceof PersistentCollection) {
						if ($refInitProperty->getValue($collection)) {
							// kolekce už je inicializovaná
							continue;
						}

						$collection->setInitialized(TRUE);
						$collection->takeSnapshot();
					}
				}
			}

			if (array_key_exists($fieldName, $childrenFieldNames)) {
				// fetchnout potomky aktuálního pole
				static::doPostFetch($em, $result, $childrenFieldNames[$fieldName]);
			}
		}
	}

	/**
	 * Spustí postFetch. Nevolat přímo.
	 * @throws ReflectionException
	 * @internal
	 */
	final public function postFetch(Iterator $iterator): void
	{
		if (empty($this->postFetch)) {
			return;
		}

		$rootEntities = iterator_to_array($iterator, TRUE);
		static::doPostFetch($this->getEntityManager(), $rootEntities, $this->postFetch);
	}

	/**
	 * Přidá pole do seznamu pro postFetch.
	 * @param string $fieldName Může být název pole (např. "contact") nebo cesta (např. "commission.contract.client").
	 * @return $this
	 */
	final public function addPostFetch(string $fieldName): static
	{
		$this->postFetch[] = $fieldName;
		return $this;
	}
}

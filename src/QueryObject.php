<?php

namespace ADT\DoctrineComponents;

use ADT\DoctrineComponents\Exception\UnexpectedValueException;
use Doctrine;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Nette;

/**
 */
abstract class QueryObject
{
	use Nette\SmartObject;

	const SELECT_PAIRS_KEY = 'id';
	const SELECT_PAIRS_VALUE = null;

	const ORDER_DEFAULT = 'order_default';

	const JOIN_INNER = 'innerJoin';
	const JOIN_LEFT = 'leftJoin';

	/**
	 * @var \Doctrine\ORM\Query|null
	 */
	private $lastQuery;

	/**
	 * @var ResultSet
	 */
	private $lastResult;

	private $selectPairsKey = null;
	private $selectPairsValue = null;

	protected $selectPrimary = false;

	/** @var array */
	protected $orByIdFilter = [];

	/**
	 * @var array|null to distinguish between an unset and an empty array
	 */
	protected $byIdFilter = null;

	protected $entityAlias = 'e';

	/**
	 * @var array|\Closure[]
	 */
	protected array $filter = [];

	/**
	 * @var array|\Closure[]
	 */
	protected array $select = [];

	/**
	 * @var array
	 */
	protected array $postFetch = [];

	/** @var bool */
	protected bool $fetchJoinCollection = FALSE;

	/**
	 * Jaká entita se bude filtrovat.
	 * @var $entityClass
	 */
	protected $entityClass = NULL;
	
	protected EntityManagerInterface $em;

	public function getEntityManager()
	{
		return $this->em;
	}

	public function setEntityManager(EntityManagerInterface $em)
	{
		$this->em = $em;
	}

	public function __construct()
	{
	}

	public function disableDefaultOrder()
	{
		unset($this->select[static::ORDER_DEFAULT]);

		return $this;
	}

	public function disableSelects($disableDefaultOrder = false)
	{
		foreach ($this->select as $key => $select) {
			if ($key === static::ORDER_DEFAULT && !$disableDefaultOrder) {
				continue;
			}

			unset($this->select[$key]);
		}

		return $this;
	}

	/**
	 * @param int|int[]|IEntity|IEntity[]|[]|null $id
	 * @return static
	 */
	public function byId($id)
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
	 * @return static
	 */
	public function orById($id)
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

	protected function addJoins(array $columns, ?string $joinType)
	{
		if (!is_null($joinType)) {
			foreach ($columns as $column) {
				if (strstr($column, '.')) {
					$aliases = explode('.', $column, -1);
					if (count($aliases)) {
						if ($aliases[0] == $this->entityAlias) {
							unset($aliases[0]);
						}
						$aliasLast = null;
						foreach ($aliases as $aliasNew) {
							$join = $aliasLast ? $aliasLast . '.' . $aliasNew : $this->addColumnPrefix($aliasNew);
							$filterKey = $this->getJoinFilterKey($joinType, $join, $aliasNew);
							if (!$this->isAlreadyJoined($filterKey)) {
								$this->commonJoin($joinType, $join, $aliasNew);
							}
							$aliasLast = $aliasNew;
						}
					}
				}
			}
		}
	}

	/**
	 * Obecná metoda na vyhledávání ve více sloupcích (spojení přes OR).
	 * Podle vyhledávané hodnoty, případně parametru strict (LIKE vs. =), se zvolí typ vyhledávání (IN, LIKE, =).
	 *
	 * @param string|string[] $column
	 * @param mixed $value
	 * @param bool $strict
	 * @return $this
	 */
	public function searchIn($column, $value, bool $strict = false, ?string $joinType = self::JOIN_INNER): self
	{
		$this->addJoins((array)$column, $joinType);

		$this->filter[] = function (QueryBuilder $qb) use ($column, $value, $strict) {
			$x = array_map(
				function($_column) use ($qb, $value, $strict) {
					$paramName = 'searchIn_' . str_replace('.', '_', $_column);
					$_column = $this->addColumnPrefix($_column);
					$_column = $this->getColumnLastTwoParts($_column);

					if (is_array($value)) {
						$condition = "$_column IN (:$paramName)";
						$qb->setParameter($paramName, $value);
					} else if (is_scalar($value) && !$strict) {
						$condition = "$_column LIKE :$paramName";
						$qb->setParameter($paramName, "%$value%");
					} else if (is_null($value)) {
						$condition = "$_column IS NULL";
					} else {
						$condition = "$_column = :$paramName";
						$qb->setParameter($paramName, $value);
					}
					return $condition;
				},
				(array)$column
			);
			$qb->andWhere($qb->expr()->orX(...$x));
		};
		return $this;
	}

	private function getColumnLastTwoParts(string $column): string {
		return implode('.', array_slice(explode('.', $column), -2));
	}

	/**
	 * @param string $column
	 * @param string $order
	 * @return self
	 */
	public function addOrderBy(string $column, string $order = 'ASC'): self
	{
		if (property_exists($this->getEntityClass(), $column)) {
			$column = $this->addColumnPrefix($column);
		}
		$this->select[] = function (QueryBuilder $qb) use ($column, $order) {
			$qb->addOrderBy($column, $order);
		};

		return $this;
	}

	/**
	 * @param string $column
	 * @param string $order
	 * @return self
	 */
	public function orderBy(string $column, string $order = 'ASC'): self
	{
		if (property_exists($this->getEntityClass(), $column)) {
			$column = $this->addColumnPrefix($column);
		}
		$this->select[] = function (QueryBuilder $qb) use ($column, $order) {
			$qb->orderBy($column, $order);
		};

		return $this;
	}

	/**
	 * @param null $value Default to static::SELECT_PAIRS_VALUE or null, which returns the entire entity
	 * @param null $key Default to static::SELECT_PAIRS_KEY or 'id'
	 * @return $this
	 */
	public function selectPairs($value = null, $key = null)
	{
		$this->selectPairsKey = $key ?: static::SELECT_PAIRS_KEY;
		$this->selectPairsValue = $value ?: static::SELECT_PAIRS_VALUE;

		$this->disableSelects();

		return $this;
	}

	/**
	 * @return bool
	 */
	public function callSelectPairsAuto()
	{
		return !$this->selectPairsKey && !$this->selectPairsValue;
	}

	/**
	 * @param null $singleValueAssociationField
	 * @return $this
	 */
	public function selectPrimary($singleValueAssociationField = null)
	{
		$this->selectPrimary = true;

		$this->disableSelects($disableDefaultOrder = true);
		
		$this->select[] = function (QueryBuilder $qb) use ($singleValueAssociationField) {
			$qb->select($singleValueAssociationField ? 'IDENTITY(e.' . $singleValueAssociationField . ') id' : 'e.id');

			if ($singleValueAssociationField) {
				$qb->groupBy('e. ' . $singleValueAssociationField);
			}
		};

		return $this;
	}

	/**
	 * @param EntityRepository|null $repository
	 * @param int $hydrationMode
	 * @return array|ResultSet|mixed
	 * @throws \Exception
	 */
	public function fetch(?EntityRepository $repository = null, $hydrationMode = AbstractQuery::HYDRATE_OBJECT)
	{
		if (is_null($repository)) {
			$repository = $this->em->getRepository($this->getEntityClass());
		}

		if ($this->selectPairsKey || $this->selectPairsValue) {
			$items = [];
			foreach ($this->doFetch($repository) as $item) {
				$key = $item->{'get' . ucfirst($this->selectPairsKey)}();
				if (!is_scalar($key)) {
					throw new \Exception('The key must not be of type `' . gettype($key) . '`.');
				}

				$items[$key] = $this->selectPairsValue ? $item->{'get' . ucfirst($this->selectPairsValue)}() : $item;
			}

			return $items;
		}

		if ($this->selectPrimary) {
			$items = [];
			foreach ($this->doFetch($repository, AbstractQuery::HYDRATE_SCALAR) as $item) {
				$items[$item['id']] = $item['id'];
			}

			return $items;
		}

		$resultSet = $this->doFetch($repository, $hydrationMode);
		if ($resultSet instanceof ResultSet && $resultSet->getFetchJoinCollection() !== $this->fetchJoinCollection) {
			$resultSet->setFetchJoinCollection($this->fetchJoinCollection);
		}
		return $resultSet;
	}

	/**
	 * @param \Doctrine\ORM\EntityRepository   $repository
	 * @param int $hydrationMode
	 *
	 * @return ResultSet|array
	 */
	public function doFetch(EntityRepository $repository, $hydrationMode = AbstractQuery::HYDRATE_OBJECT)
	{
		$query = $this->getQuery($repository)
			->setFirstResult(NULL)
			->setMaxResults(NULL);

		return $hydrationMode !== AbstractQuery::HYDRATE_OBJECT
			? $query->execute(NULL, $hydrationMode)
			: $this->lastResult;
	}

	/**
	 * @param EntityRepository|null $repository
	 * @return object
	 * @throws \Doctrine\ORM\NoResultException
	 */
	public function fetchOne(?EntityRepository $repository = null)
	{
		if (is_null($repository)) {
			$repository = $this->em->getRepository($this->getEntityClass());
		}

		$query = $this->getQuery($repository)
			->setFirstResult(NULL)
			->setMaxResults(1);

		// getResult has to be called to have consistent result for the postFetch
		// this is the only way to main the INDEX BY value
		$singleResult = $query->getResult();

		if (!$singleResult) {
			throw new Doctrine\ORM\NoResultException(); // simulate getSingleResult()
		}

		$this->postFetch($repository, new \ArrayIterator($singleResult));

		return array_shift($singleResult);
	}

	/**
	 * @param EntityRepository|null $repository
	 * @return object|null
	 */
	public function fetchOneOrNull(?EntityRepository $repository = null)
	{
		try {
			return $this->fetchOne($repository);
		} catch (\Doctrine\ORM\NoResultException $e) {
			return NULL;
		}
	}


	/**
	 * Spustí postFetch. Nevolat přímo.
	 * @param EntityRepository $repository
	 * @param \Iterator $iterator
	 * @return void
	 */
	public function postFetch(EntityRepository $repository, \Iterator $iterator): void
	{
		if (empty($this->postFetch)) {
			return;
		}

		$rootEntities = iterator_to_array($iterator, TRUE);
		static::doPostFetch($repository->getEntityManager(), $rootEntities, $this->postFetch);
	}

	/**
	 * Přidá pole do seznamu pro postFetch.
	 * @param string $fieldName Může být název pole (např. "contact") nebo cesta (např. "commission.contract.client").
	 * @return $this
	 */
	public function addPostFetch(string $fieldName): self
	{
		$this->postFetch[] = $fieldName;
		return $this;
	}

	/**
	 * @param EntityRepository $repository
	 * @return int
	 */
	public function delete(\Doctrine\ORM\EntityRepository   $repository)
	{
		return $this->doCreateDeleteQuery($repository)->getQuery()->execute();
	}

	/**
	 * @param EntityRepository $repository
	 * @return \Doctrine\ORM\Query|\Doctrine\ORM\QueryBuilder
	 */
	public function toQueryBuilder(\Doctrine\ORM\EntityRepository   $repository)
	{
		return $this->doCreateQuery($repository);
	}

	/**
	 * @param EntityManagerInterface $em
	 * @param IEntity[] $rootEntities Jeden typ entit, např. 10x User.
	 * @param string[] $fieldNames Názvy relací v hlavní entitě. Pro zanoření použij '.'. Např. [ 'address' ].
	 */
	public static function doPostFetch(EntityManagerInterface $em, array $rootEntities, array $fieldNames): void
	{
		if (empty($rootEntities)) {
			return;
		}

		$currentFieldNames = [];
		$childrenFieldNames = []; // fieldName => childrenFieldNames

		foreach ($fieldNames as $fieldName) {
			if (!\Nette\Utils\Strings::contains($fieldName, '.')) {
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
			$rootEntities = \Nette\Utils\Arrays::associate($rootEntities, '[]=0');
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
				sprintf('PostFetch: Entita %s nemá pole %s.', get_class($firstRootEntity), $fieldName);
			}
		}
		$fieldNames = array_intersect($availableFieldNames, $fieldNames);

		// připravíme QueryBuilder pro vytažení IDček *_TO_ONE asociací, např. z Userů
		$qb = $em->getRepository(get_class($firstRootEntity))->createQueryBuilder('e')
			->select('PARTIAL e.{id} AS e_id')
			->andWhere('e.id IN (:ids)', $rootIds);

		// budeme si je počítat, abychom nedělali prázdný dotaz
		$toOneAssociations = 0;

		foreach ($fieldNames as $i => $fieldName) {
			// $fieldName je např. 'address'
			$association = $rootEntityAssociations[$fieldName];

			if ($association['type'] & \Doctrine\ORM\Mapping\ClassMetadata::TO_ONE) {
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
				bd('PostFetch: Nelze přiřadit entity k root entitě. Chybí mappedBy nebo inversedBy. ' . sprintf('(rootEntity=%s; targetEntity=%s)', $association['sourceEntity'], $association['targetEntity']));
				continue;
			}

			// pro každou asociaci (např. 'address') si připravíme QueryBuilder
			$qb = $em->createQueryBuilder()
				->select('e')
				->from($association['targetEntity'], 'e');

			if ($association['type'] & \Doctrine\ORM\Mapping\ClassMetadata::TO_ONE) {
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
					->orWhere('e.id IN (:ids)', array_unique($ids));
			} elseif ($association['type'] === \Doctrine\ORM\Mapping\ClassMetadata::ONE_TO_MANY) {
				// u ONE_TO_MANY asociací stačí selectovat podle IDček rootovských entit
				// např. jeden User má více adres, v adrese je nastaven User
				$qb
					->orWhere('e.' . $association['mappedBy'] . ' IN (:ids)', array_unique($rootIds));
			} elseif ($association['type'] === \Doctrine\ORM\Mapping\ClassMetadata::MANY_TO_MANY) {
				// u MANY_TO_MANY asociací musíme (např. adresu) joinovat s root entitou (User) a pak selectovat podle IDček rootovských entit
				$qb
					->leftJoin('e.' . $propertyName, $propertyName)
					->orWhere($propertyName . '.id IN (:ids)', array_unique($rootIds));
			} else {
				continue;
			}

			// provedeme select (např. adres)
			$result = $qb
				->getQuery()
				->getResult();

			if ($association['type'] & \Doctrine\ORM\Mapping\ClassMetadata::TO_ONE) {
				// Doctrina nám entity přiřadí
			} elseif ($association['type'] & \Doctrine\ORM\Mapping\ClassMetadata::TO_MANY) {
				$refCollProperty = new \Nette\Reflection\Property(get_class($firstRootEntity), $association['fieldName']);
				$refCollProperty->accessible = TRUE;

				$refInitProperty = new \Nette\Reflection\Property(\Doctrine\ORM\PersistentCollection::class, 'initialized');
				$refInitProperty->accessible = TRUE;

				if ($association['type'] === \Doctrine\ORM\Mapping\ClassMetadata::MANY_TO_MANY) {
					// u MANY_TO_MANY relací se nám ztratila informace o tom, která entita patří do jaké kolekce,
					// dalším dotazem tedy zjistíme co kam máme dát

					$manyToManyMapping = $em->createQueryBuilder()
						->from($association['targetEntity'], 'e')
						->select('e.id AS childEntityId, ' . $propertyName . '.id AS rootEntityId')
						->leftJoin('e.' . $propertyName, $propertyName)
						->andWhere($propertyName . '.id IN (:ids)', array_unique($rootIds))
						->getQuery()
						->getArrayResult();
				}

				// přiřadíme výsledky tam, kam patří
				foreach ($result as $row) {
					$collections = [];

					if ($association['type'] !== \Doctrine\ORM\Mapping\ClassMetadata::MANY_TO_MANY) {
						$reflector = new \ReflectionClass($row);
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
						if ($collection instanceof \Doctrine\ORM\PersistentCollection) {
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
					if ($collection instanceof \Doctrine\ORM\PersistentCollection) {
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
	 * @param string|null $column
	 * @return string
	 */
	protected function addColumnPrefix(string $column = NULL): string {
		if ((strpos($column, '.') === FALSE) && (strpos($column, '\\') === FALSE)) {
			$column = $this->entityAlias . '.' . $column;
		}
		return $column;
	}

	protected function join(string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): self
	{
		return $this->innerJoin($join, $alias, $conditionType, $condition, $indexBy);
	}

	protected function leftJoin(string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): self
	{
		return $this->commonJoin(__FUNCTION__, $join, $alias, $conditionType, $condition, $indexBy);
	}

	protected function innerJoin(?string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): self
	{
		return $this->commonJoin(__FUNCTION__, $join, $alias, $conditionType, $condition, $indexBy);
	}

	private function commonJoin(string $joinType, string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null): self
	{
		$join = $this->addColumnPrefix($join);
		$filterKey = $this->getJoinFilterKey($joinType, $join, $alias, $conditionType, $condition, $indexBy);
		$this->filter[$filterKey] = function (QueryBuilder $qb) use ($joinType, $join, $alias, $conditionType, $condition, $indexBy) {
			$qb->$joinType($join, $alias, $conditionType, $condition, $indexBy);
		};
		return $this;
	}

	private function getJoinFilterKey(string $joinType, string $join, string $alias, ?string $conditionType = null, ?string $condition = null, ?string $indexBy = null)
	{
		return implode('_', [$joinType, $join, $alias, $conditionType, $condition, $indexBy]);
	}

	private function isAlreadyJoined(string $filterKey)
	{
		$filterKey = str_replace(self::JOIN_INNER, '', $filterKey);
		$filterKey = str_replace(self::JOIN_LEFT, '', $filterKey);
		$filterKeyInner = self::JOIN_INNER . $filterKey;
		$filterKeyLeft = self::JOIN_LEFT . $filterKey;

		return isset($this->filter[$filterKeyInner]) || isset($this->filter[$filterKeyLeft]);
	}

	/**
	 * @param \Doctrine\ORM\EntityRepository   $repository
	 * @return \Doctrine\ORM\Query|\Doctrine\ORM\QueryBuilder
	 */
	protected function doCreateQuery(EntityRepository $repository): QueryBuilder
	{
		$qb = $this->doCreateBasicQuery($repository);

		foreach ($this->select as $modifier) {
			$modifier($qb);
		}

		return $qb;
	}

	protected function doCreateCountQuery(EntityRepository $repository): ?QueryBuilder
	{
		$qb = $this->doCreateBasicQuery($repository);
		if ($qb->getDQLPart('groupBy')) {
			return null;
		}

		return $qb->select('COUNT(e.id)');
	}

	private function doCreateBasicQuery(EntityRepository $repository): QueryBuilder
	{
		$qb = $repository->createQueryBuilder('e');

		// we need to use a reference to allow adding a filter inside another filter
		foreach ($this->filter as &$modifier) {
			$modifier($qb);
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

		return $qb;
	}

	private function doCreateDeleteQuery(EntityRepository $repository): QueryBuilder
	{
		$qb = $repository->createQueryBuilder('e');

		$qb->delete($this->getEntityClass(), 'e');

		foreach ($this->filter as $modifier) {
			$modifier($qb);
		}

		return $qb;
	}

	protected function getEntityClass(): string
	{
		if ($this->entityClass) {
			return $this->entityClass;
		}

		$fullClassQueryName = get_class($this);
		$fullClassEntityName = str_replace("Queries", "Entity", $fullClassQueryName);
		$fullClassEntityName = str_replace("Query", "", $fullClassEntityName);

		return $fullClassEntityName;
	}

	/**
	 * @param \Doctrine\ORM\EntityRepository   $repository
	 *
	 * @throws UnexpectedValueException
	 * @return \Doctrine\ORM\Query
	 */
	protected function getQuery(EntityRepository $repository)
	{
		$query = $this->toQuery($this->doCreateQuery($repository));

		if ($this->lastQuery instanceof Doctrine\ORM\Query && $query instanceof Doctrine\ORM\Query &&
			$this->lastQuery->getDQL() === $query->getDQL()) {
			$query = $this->lastQuery;
		}

		if ($this->lastQuery !== $query) {
			$this->lastResult = new ResultSet($query, $this, $repository);
		}

		return $this->lastQuery = $query;
	}
	
	public function count(EntityRepository $repository = null, ResultSet $resultSet = null, Paginator $paginatedQuery = null)
	{
		if (is_null($repository)) {
			$repository = $this->em->getRepository($this->getEntityClass());
		}

		if ($query = $this->doCreateCountQuery($repository)) {
			return (int)$this->toQuery($query)->getSingleScalarResult();
		}

		if ($paginatedQuery !== NULL) {
			return $paginatedQuery->count();
		}

		$query = $this->getQuery($repository)
			->setFirstResult(NULL)
			->setMaxResults(NULL);

		$paginatedQuery = new Paginator($query, ($resultSet !== NULL) ? $resultSet->getFetchJoinCollection() : TRUE);
		$paginatedQuery->setUseOutputWalkers(($resultSet !== NULL) ? $resultSet->getUseOutputWalkers() : NULL);

		return $paginatedQuery->count();
	}

	/**
	 * @internal For Debugging purposes only!
	 * @return \Doctrine\ORM\Query|null
	 */
	public function getLastQuery()
	{
		return $this->lastQuery;
	}

	/**
	 * @param \Doctrine\ORM\QueryBuilder|AbstractQuery $query
	 * @return Doctrine\ORM\Query
	 */
	private function toQuery($query)
	{
		if ($query instanceof Doctrine\ORM\QueryBuilder) {
			$query = $query->getQuery();
		}

		if (!$query instanceof Doctrine\ORM\Query) {
			throw new UnexpectedValueException(sprintf(
				"Method " . get_called_class() . "::doCreateQuery must return " .
				"instanceof %s or %s, " .
				(is_object($query) ? 'instance of ' . get_class($query) : gettype($query)) . " given.",
				\Doctrine\ORM\Query::class,
				QueryBuilder::class
			));
		}

		return $query;
	}

}

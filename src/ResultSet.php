<?php

namespace ADT\DoctrineComponents;

use ArrayIterator;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Exception;
use IteratorAggregate;
use Nette\Utils\Paginator;
use Traversable;

class ResultSet implements IteratorAggregate
{
	private QueryObject $qo;
	private int $page;
	private ?int $itemsPerPage;

	private ?int $count = null;
	private ?Traversable $results = null;
	private ?Paginator $paginator = null;

	public function __construct(QueryObject $qo, int $page, int $itemsPerPage)
	{
		$this->qo = $qo;
		$this->page = $page;
		$this->itemsPerPage = $itemsPerPage;
	}

	/**
	 * @throws NonUniqueResultException
	 * @throws NoResultException
	 */
	public function getPaginator(): Paginator
	{
		if ($this->paginator) {
			return $this->paginator;
		}

		$count = $this->count();

		$paginator = new Paginator();
		$paginator->setItemCount($count);
		$paginator->setPage($this->page);
		$paginator->setItemsPerPage($this->itemsPerPage);

		return $this->paginator = $paginator;
	}

	/**
	 * @throws NonUniqueResultException
	 * @throws NoResultException
	 */
	public function count(): int
	{
		if ($this->count !== null) {
			return $this->count;
		}

		return $this->count = $this->qo->count();
	}

	/**
	 * @throws Exception
	 */
	public function getIterator(): Traversable
	{
		if ($this->results !== null) {
			return $this->results;
		}

		$this->results = new ArrayIterator(
			$this->qo->getQuery()
				->setMaxResults($this->itemsPerPage)
				->setFirstResult($this->itemsPerPage * ($this->page - 1))
				->getResult()
		);

		$this->qo->postFetch($this->results);

		return $this->results;
	}
}

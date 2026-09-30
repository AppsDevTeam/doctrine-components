<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\QueryObject;

use ADT\DoctrineComponents\QueryObject\QueryObject;
use ADT\DoctrineComponents\Tests\Fixtures\Entity\Book;

/**
 * @extends QueryObject<Book>
 */
class BookQueryObject extends QueryObject
{
	public function getEntityClass(): string
	{
		return Book::class;
	}

	protected function setDefaultOrder(): void
	{
		$this->orderBy('id', 'ASC');
	}
}

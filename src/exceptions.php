<?php

/**
 * This file is part of the Kdyby (http://www.Kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace ADT\BaseQuery;

use Doctrine;



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
interface Exception
{

}



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class InvalidStateException extends \RuntimeException implements Exception
{

}



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class InvalidArgumentException extends \InvalidArgumentException implements Exception
{

}



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class NotSupportedException extends \LogicException implements Exception
{

}



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class StaticClassException extends \LogicException implements Exception
{

}



/**
 * The exception that is thrown when a requested method or operation is not implemented.
 */
class NotImplementedException extends \LogicException implements Exception
{

}



/**
 * When class is not found
 */
class MissingClassException extends \LogicException implements Exception
{

}



/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class UnexpectedValueException extends \UnexpectedValueException implements Exception
{

}




/**
 * @author Filip Procházka <filip@prochazka.su>
 */
class QueryException extends \RuntimeException implements Exception
{

	/**
	 * @var \Doctrine\ORM\AbstractQuery|NULL
	 */
	public $query;



	/**
	 * @param \Exception|\Throwable $previous
	 * @param \Doctrine\ORM\AbstractQuery|NULL $query
	 * @param string|NULL $message
	 */
	public function __construct($previous, Doctrine\ORM\AbstractQuery $query = NULL, $message = NULL)
	{
		parent::__construct($message ?: $previous->getMessage(), 0, $previous);
		$this->query = $query;
	}

}



/**
 * @author Michael Moravec
 */
class ReadOnlyCollectionException extends NotSupportedException
{
	/**
	 * @throws ReadOnlyCollectionException
	 */
	public static function invalidAccess($what)
	{
		return new static('Could not ' . $what . ' read-only collection, write/modify operations are forbidden.');
	}
}

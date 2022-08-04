<?php

namespace ADT\DoctrineComponents;

interface Exception
{

}

class PageIsOutOfRangeException extends \OutOfRangeException implements Exception
{

}

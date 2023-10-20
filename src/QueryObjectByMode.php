<?php

namespace ADT\DoctrineComponents;

enum QueryObjectByMode: string
{
	case STRICT = 'strict';
	case NOT_EQUAL = 'notEqual';
	case STARTS_WITH = 'startsWith';
	case ENDS_WITH = 'endsWith';
	case CONTAINS = 'contains';
	case NOT_CONTAINS = 'notContains';
	case IS_EMPTY = 'isEmpty';
	case IS_NOT_EMPTY = 'isNotEmpty';
	case IN_ARRAY = 'isInArray';
	case GREATER = 'greater';
	case GREATER_OR_EQUAL = 'greaterOrEqual';
	case LESS = 'less';
	case LESS_OR_EQUAL = 'lessOrEqual';
	case BETWEEN = 'between';
	case NOT_BETWEEN = 'notBetween';
}

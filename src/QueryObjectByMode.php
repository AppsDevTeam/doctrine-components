<?php

namespace ADT\DoctrineComponents;

enum QueryObjectByMode: string
{
	case AUTO = 'auto';
	case EQUAL = 'equal';
	case NOT_EQUAL = 'notEqual';
	case STARTS_WITH = 'startsWith';
	case ENDS_WITH = 'endsWith';
	case CONTAINS = 'contains';
	case NOT_CONTAINS = 'notContains';
	case IS_NULL = 'isNull';
	case IS_NOT_NULL = 'isNotNull';
	case IN_ARRAY = 'isInArray';
	case NOT_IN_ARRAY = 'isNotInArray';
	case GREATER = 'greater';
	case GREATER_OR_EQUAL = 'greaterOrEqual';
	case LESS = 'less';
	case LESS_OR_EQUAL = 'lessOrEqual';
	case BETWEEN = 'between';
	case NOT_BETWEEN = 'notBetween';
	case MEMBER_OF = 'memberOf';
	case IS_EMPTY = 'isEmpty';
	case IS_NOT_EMPTY = 'isNotEmpty';
}

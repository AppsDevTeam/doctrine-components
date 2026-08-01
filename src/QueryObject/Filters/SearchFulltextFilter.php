<?php

namespace ADT\DoctrineComponents\QueryObject\Filters;

interface SearchFulltextFilter
{
	/**
	 * Default value of MySQL innodb_ft_min_token_size. Words shorter than that are not
	 * indexed, override SearchFulltextFilterTrait::getFulltextMinWordLength if your
	 * server is configured differently.
	 */
	const int FULLTEXT_MIN_WORD_LENGTH = 3;

	/**
	 * @param string $column column with a FULLTEXT index, optionally a path over relations (eg. "location.searchString")
	 */
	public function searchFulltext(string $column, string $value): static;
}
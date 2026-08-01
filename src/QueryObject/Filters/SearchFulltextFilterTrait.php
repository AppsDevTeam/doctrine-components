<?php

namespace ADT\DoctrineComponents\QueryObject\Filters;

use Doctrine\ORM\QueryBuilder;

trait SearchFulltextFilterTrait
{
	/**
	 * Searches a column with a FULLTEXT index (MySQL only). The value is split into words,
	 * all of them have to match (AND) and each of them matches from the beginning of a word.
	 *
	 * Requires the match_against DQL function to be registered, eg.:
	 * customStringFunctions:
	 *     match_against: DoctrineExtensions\Query\Mysql\MatchAgainst
	 *
	 * @param string $column column with a FULLTEXT index, optionally a path over relations (eg. "location.searchString")
	 */
	final public function searchFulltext(string $column, string $value): static
	{
		$words = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
		if (!$words) {
			return $this;
		}

		$this->filter[] = function (QueryBuilder $qb) use ($column, $words) {
			$this->validateFieldNames([$column => null]);
			$this->addJoins($qb, [$column]);

			$columnName = $this->getJoinedEntityColumnName($this->addColumnPrefix($column));
			$paramName = 'searchFulltext_' . str_replace('.', '_', $column);

			$fulltextWords = [];
			foreach ($words as $index => $word) {
				// words shorter than the minimum token size are not in the FULLTEXT index
				if (mb_strlen($word) < $this->getFulltextMinWordLength()) {
					$qb->andWhere("$columnName LIKE :{$paramName}_$index")
						->setParameter($paramName . '_' . $index, '%' . $word . '%');
				} else {
					$fulltextWords[] = '+' . $word . '*';
				}
			}

			if ($fulltextWords) {
				$qb->andWhere("MATCH_AGAINST($columnName) AGAINST (:$paramName IN BOOLEAN MODE) > 0")
					->setParameter($paramName, implode(' ', $fulltextWords));
			}
		};

		return $this;
	}

	protected function getFulltextMinWordLength(): int
	{
		return SearchFulltextFilter::FULLTEXT_MIN_WORD_LENGTH;
	}
}

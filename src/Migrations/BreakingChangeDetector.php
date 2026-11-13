<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Migrations;

/**
 * Finds schema changes in a SQL statement that break the code running on the old schema - they belong to a contract
 * migration (see ContractMigration), not to an expand one. A heuristic over the statement text (MySQL and
 * PostgreSQL DDL):
 *
 * - DROP TABLE / DROP VIEW, RENAME TABLE, ALTER TABLE ... RENAME [TO]
 * - ALTER TABLE ... DROP [COLUMN], RENAME COLUMN, CHANGE old new (a rename)
 * - ALTER TABLE ... ALTER [COLUMN] ... SET NOT NULL (PostgreSQL)
 *
 * Not detected: NOT NULL on MySQL (`CHANGE`/`MODIFY` repeat the whole column definition, the statement does not say
 * what changed) and a narrowed type - they need the same care.
 */
final class BreakingChangeDetector
{
	private const SAFE_DROP = 'FOREIGN|INDEX|KEY|PRIMARY|CONSTRAINT|CHECK|DEFAULT|PARTITION|IDENTITY|NOT';
	private const SAFE_RENAME = 'INDEX|KEY|CONSTRAINT';

	/**
	 * @return string|null what breaks the old code (e.g. `customer: DROP COLUMN legacy_name`), null for a compatible statement
	 */
	public static function detect(string $sql): ?string
	{
		$sql = trim((string) preg_replace('/\s+/', ' ', str_replace(['`', '"'], '', $sql)), " \t\n\r\0\x0B;");
		$upper = strtoupper($sql);

		if (preg_match('/^DROP (TABLE|VIEW)\b/', $upper)) {
			return self::short($sql);
		}
		if (str_starts_with($upper, 'RENAME TABLE ')) {
			return self::short($sql);
		}
		if (!preg_match('/^ALTER TABLE (?:IF EXISTS )?(?:ONLY )?(\S+) (.*)$/i', $sql, $m)) {
			return null;
		}

		[, $table, $actions] = $m;
		foreach (self::splitActions($actions) as $action) {
			$reason = self::actionReason($action);
			if ($reason !== null) {
				return "$table: $reason";
			}
		}

		return null;
	}

	private static function actionReason(string $action): ?string
	{
		$upper = strtoupper($action);

		if (preg_match('/^DROP (?:COLUMN )?(?:IF EXISTS )?(?!(?:' . self::SAFE_DROP . ')\b)\S+/', $upper)) {
			return self::short($action);
		}
		if (preg_match('/^RENAME (?!(?:' . self::SAFE_RENAME . ')\b)/', $upper)) {
			return self::short($action);
		}
		if (preg_match('/^CHANGE (?:COLUMN )?(\S+) (\S+)/', $upper, $m) && $m[1] !== $m[2]) {
			return self::short($action);
		}
		if (preg_match('/^ALTER (?:COLUMN )?\S+ SET NOT NULL\b/', $upper)) {
			return self::short($action);
		}

		return null;
	}

	/**
	 * Actions of ALTER TABLE separated by commas outside of parentheses and string literals.
	 *
	 * @return list<string>
	 */
	private static function splitActions(string $actions): array
	{
		$result = [];
		$current = '';
		$depth = 0;
		$inString = false;
		foreach (str_split($actions) as $char) {
			if ($char === "'") {
				$inString = !$inString;
			} elseif (!$inString && $char === '(') {
				$depth++;
			} elseif (!$inString && $char === ')') {
				$depth--;
			} elseif (!$inString && $depth === 0 && $char === ',') {
				$result[] = trim($current);
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$result[] = trim($current);

		return $result;
	}

	private static function short(string $sql): string
	{
		return strlen($sql) > 80 ? substr($sql, 0, 77) . '...' : $sql;
	}
}

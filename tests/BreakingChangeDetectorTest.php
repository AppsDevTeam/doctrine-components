<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Migrations\BreakingChangeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BreakingChangeDetectorTest extends TestCase
{
	/**
	 * @return iterable<string, array{string, string|null}>
	 */
	public static function statements(): iterable
	{
		yield 'drop table' => ['DROP TABLE legacy', 'DROP TABLE legacy'];
		yield 'drop table if exists' => ['DROP TABLE IF EXISTS `legacy`', 'DROP TABLE IF EXISTS legacy'];
		yield 'drop view' => ['DROP VIEW customer_view', 'DROP VIEW customer_view'];
		yield 'drop temporary table' => ['DROP TEMPORARY TABLE tmp_import', null];
		yield 'rename table' => ['RENAME TABLE customer TO client', 'RENAME TABLE customer TO client'];
		yield 'alter rename to' => ['ALTER TABLE customer RENAME TO client', 'customer: RENAME TO client'];
		yield 'alter rename (postgres, quoted)' => ['ALTER TABLE "customer" RENAME TO "client"', 'customer: RENAME TO client'];
		yield 'drop column' => ['ALTER TABLE customer DROP COLUMN legacy_name', 'customer: DROP COLUMN legacy_name'];
		yield 'drop column without keyword' => ['ALTER TABLE `customer` DROP `legacy_name`', 'customer: DROP legacy_name'];
		yield 'drop column among safe actions' => ['ALTER TABLE customer ADD email VARCHAR(255) DEFAULT NULL, DROP legacy_name', 'customer: DROP legacy_name'];
		yield 'drop column if exists' => ['ALTER TABLE customer DROP COLUMN IF EXISTS legacy_name', 'customer: DROP COLUMN IF EXISTS legacy_name'];
		yield 'rename column' => ['ALTER TABLE customer RENAME COLUMN name TO full_name', 'customer: RENAME COLUMN name TO full_name'];
		yield 'change = rename' => ['ALTER TABLE customer CHANGE name full_name VARCHAR(255) NOT NULL', 'customer: CHANGE name full_name VARCHAR(255) NOT NULL'];
		yield 'change column = rename' => ['ALTER TABLE customer CHANGE COLUMN `name` `full_name` VARCHAR(255) NOT NULL', 'customer: CHANGE COLUMN name full_name VARCHAR(255) NOT NULL'];
		yield 'change same name' => ['ALTER TABLE customer CHANGE name name VARCHAR(500) NOT NULL', null];
		yield 'change same name, other case' => ['ALTER TABLE customer CHANGE Name name VARCHAR(500) DEFAULT NULL', null];
		yield 'modify' => ['ALTER TABLE customer MODIFY name VARCHAR(500) NOT NULL', null];
		yield 'set not null (postgres)' => ['ALTER TABLE customer ALTER COLUMN name SET NOT NULL', 'customer: ALTER COLUMN name SET NOT NULL'];
		yield 'set not null without column keyword' => ['ALTER TABLE customer ALTER name SET NOT NULL', 'customer: ALTER name SET NOT NULL'];
		yield 'drop not null' => ['ALTER TABLE customer ALTER COLUMN name DROP NOT NULL', null];
		yield 'drop default' => ['ALTER TABLE customer ALTER COLUMN name DROP DEFAULT', null];
		yield 'drop foreign key' => ['ALTER TABLE customer DROP FOREIGN KEY FK_81398E09A76ED395', null];
		yield 'drop index' => ['ALTER TABLE customer DROP INDEX IDX_81398E09A76ED395', null];
		yield 'drop key' => ['ALTER TABLE customer DROP KEY IDX_1', null];
		yield 'drop primary key' => ['ALTER TABLE customer DROP PRIMARY KEY, ADD PRIMARY KEY (id, type)', null];
		yield 'drop constraint' => ['ALTER TABLE customer DROP CONSTRAINT FK_1', null];
		yield 'drop index statement' => ['DROP INDEX IDX_1 ON customer', null];
		yield 'rename index' => ['ALTER TABLE customer RENAME INDEX idx_a TO idx_b', null];
		yield 'rename key' => ['ALTER TABLE customer RENAME KEY idx_a TO idx_b', null];
		yield 'add column' => ['ALTER TABLE customer ADD legacy_name VARCHAR(255) DEFAULT NULL', null];
		yield 'add column with literal' => ["ALTER TABLE customer ADD note VARCHAR(255) DEFAULT 'DROP COLUMN, RENAME TO x'", null];
		yield 'add foreign key' => ['ALTER TABLE note ADD CONSTRAINT FK_X FOREIGN KEY (a) REFERENCES b (id)', null];
		yield 'create table' => ['CREATE TABLE department (id INT AUTO_INCREMENT NOT NULL, PRIMARY KEY(id)) ENGINE = InnoDB', null];
		yield 'create index' => ['CREATE INDEX IDX_1 ON customer (department_id)', null];
		yield 'update with literal' => ["UPDATE customer SET note = 'DROP TABLE customer'", null];
		yield 'delete' => ['DELETE FROM customer WHERE legacy = 1', null];
		yield 'multiline' => ["ALTER TABLE customer\n\tDROP COLUMN legacy_name;", 'customer: DROP COLUMN legacy_name'];
		yield 'long statement is shortened' => [
			'ALTER TABLE customer DROP COLUMN ' . str_repeat('x', 100),
			'customer: DROP COLUMN ' . str_repeat('x', 65) . '...',
		];
	}

	#[DataProvider('statements')]
	public function testDetect(string $sql, ?string $expected): void
	{
		$this->assertSame($expected, BreakingChangeDetector::detect($sql));
	}
}

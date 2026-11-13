<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Migrations;

/**
 * Expand and Contract pattern: a schema change is split in two migrations so that both the old and the new code
 * can run on one database. A deploy runs the migrations first and switches the release only afterwards (and after
 * a rollback the old code runs again), so until the switch the old code works with the migrated schema.
 *
 * - Expand (default, without this interface): a backward compatible change - a new table, a new nullable column
 *   or a column with a default, a new index, a data backfill. The old code must keep working with it.
 * - Contract (implements ContractMigration): a cleanup that breaks the old code - DROP of a column/table,
 *   NOT NULL on a previously nullable column, a rename. It runs only once its code is the running release
 *   (`current` of the Deployer layout), i.e. one deploy later, or when requested explicitly
 *   (`migrations:migrate --phase=contract`).
 *
 * One change = two migrations: expand adds the new, contract (in the same or a later release) removes the old.
 * The dry-run of `migrations:migrate` fails when an expand migration contains a breaking change
 * (BreakingChangeDetector).
 */
interface ContractMigration
{
}

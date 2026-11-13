<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Migrations;

use Doctrine\Migrations\AbstractMigration;

/**
 * Phase of a migration in the Expand and Contract pattern (see ContractMigration).
 */
enum MigrationPhase: string
{
	case Expand = 'expand';
	case Contract = 'contract';

	public static function of(AbstractMigration $migration): self
	{
		return $migration instanceof ContractMigration ? self::Contract : self::Expand;
	}
}

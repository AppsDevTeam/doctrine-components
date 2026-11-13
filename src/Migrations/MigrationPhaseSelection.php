<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Migrations;

use Doctrine\Migrations\Version\Version;

final readonly class MigrationPhaseSelection
{
	/**
	 * @param list<Version> $versions migrations to run
	 * @param list<Version> $deferred contract migrations whose code is not running yet - they wait for the next deploy
	 * @param list<Version> $withoutRelease contract migrations run right away because no running release was found
	 *                                      for them (first deploy, local development, a file outside of the releases)
	 */
	public function __construct(
		public array $versions,
		public array $deferred = [],
		public array $withoutRelease = [],
	) {
	}
}

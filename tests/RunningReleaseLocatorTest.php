<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests;

use ADT\DoctrineComponents\Migrations\RunningReleaseLocator;
use PHPUnit\Framework\TestCase;

class RunningReleaseLocatorTest extends TestCase
{
	private string $deployPath;

	protected function setUp(): void
	{
		$this->deployPath = sys_get_temp_dir() . '/adt-dc-locator-' . uniqid();
		mkdir("$this->deployPath/releases/1/migrations", 0777, true);
		mkdir("$this->deployPath/releases/2/migrations", 0777, true);
		touch("$this->deployPath/releases/2/migrations/Version2.php");
	}

	protected function tearDown(): void
	{
		TestDirectory::remove($this->deployPath);
	}

	public function testNewReleaseFindsTheFileOfTheRunningRelease(): void
	{
		symlink('releases/1', "$this->deployPath/current");

		$this->assertSame(
			realpath($this->deployPath) . '/current/migrations/Version2.php',
			(new RunningReleaseLocator())->locate("$this->deployPath/releases/2/migrations/Version2.php"),
		);
	}

	public function testRunningReleaseFindsItself(): void
	{
		symlink('releases/2', "$this->deployPath/current");

		$this->assertSame(
			realpath($this->deployPath) . '/current/migrations/Version2.php',
			(new RunningReleaseLocator())->locate("$this->deployPath/current/migrations/Version2.php"),
		);
	}

	public function testReleasesDirectoryInsideTheReleaseIsSkipped(): void
	{
		symlink('releases/1', "$this->deployPath/current");
		mkdir("$this->deployPath/releases/2/app/releases/notes", 0777, true);
		touch("$this->deployPath/releases/2/app/releases/notes/Version2.php");

		$this->assertSame(
			realpath($this->deployPath) . '/current/app/releases/notes/Version2.php',
			(new RunningReleaseLocator())->locate("$this->deployPath/releases/2/app/releases/notes/Version2.php"),
		);
	}

	public function testFirstDeployWithoutRunningReleaseFindsNothing(): void
	{
		$this->assertNull((new RunningReleaseLocator())->locate("$this->deployPath/releases/2/migrations/Version2.php"));
	}

	public function testOutsideOfReleaseFindsNothing(): void
	{
		mkdir("$this->deployPath/standalone/migrations", 0777, true);
		touch("$this->deployPath/standalone/migrations/Version2.php");

		$this->assertNull((new RunningReleaseLocator())->locate("$this->deployPath/standalone/migrations/Version2.php"));
		$this->assertNull((new RunningReleaseLocator())->locate("$this->deployPath/no-such-file.php"));
	}
}

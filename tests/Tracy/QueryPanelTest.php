<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Tracy;

use ADT\DoctrineComponents\SqlLogger;
use ADT\DoctrineComponents\Tests\TestCase;
use ADT\DoctrineComponents\Tracy\QueryPanel\QueryPanel;
use Tracy\IBarPanel;

final class QueryPanelTest extends TestCase
{
	public function testItIsATracyBarPanel(): void
	{
		self::assertInstanceOf(IBarPanel::class, new QueryPanel(new SqlLogger([])));
	}

	public function testTabWithoutQueriesShowsNoCounter(): void
	{
		$tab = (new QueryPanel(new SqlLogger([])))->getTab();

		self::assertStringContainsString('<span title="dbal">', $tab);
		self::assertStringContainsString('tracy-label', $tab);
		self::assertStringNotContainsString(' q', $tab);
		self::assertStringNotContainsString('ms', $tab);
	}

	public function testTabShowsTheQueryCountAndTotalTime(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1', ['duration' => 0.0123]);
		$logger->debug('SELECT 2', ['duration' => 0.0002]);

		$tab = (new QueryPanel($logger))->getTab();

		self::assertStringContainsString('2 q', $tab);
		self::assertStringContainsString("12.5\u{202F}ms", $tab);
	}

	public function testTabUsesDifferentIconsDependingOnTheQueryCount(): void
	{
		$empty = new SqlLogger([]);
		$withQuery = new SqlLogger([]);
		$withQuery->debug('SELECT 1', ['duration' => 0.001]);

		self::assertNotSame((new QueryPanel($empty))->getTab(), (new QueryPanel($withQuery))->getTab());
	}

	public function testPanelWithoutQueriesRendersTheEmptyState(): void
	{
		$panel = (new QueryPanel(new SqlLogger([])))->getPanel();

		self::assertStringContainsString('<h1>No queries</h1>', $panel);
		self::assertStringNotContainsString('<table class="tracy-sortable">', $panel);
	}

	public function testPanelRendersTheQueryTable(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1 FROM author', ['duration' => 0.0123]);

		$panel = (new QueryPanel($logger))->getPanel();

		self::assertStringContainsString('<h1>Queries:', $panel);
		self::assertStringContainsString('<table class="tracy-sortable">', $panel);
		self::assertStringContainsString('SELECT 1 FROM author', $panel);
		self::assertStringContainsString('12.30', $panel);
	}

	public function testPanelRendersConnectionParameters(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1', ['duration' => 0.001]);
		$logger->info('Connecting', ['params' => ['host' => 'my-database-host', 'password' => '<redacted>']]);

		$panel = (new QueryPanel($logger))->getPanel();

		self::assertStringContainsString('my-database-host', $panel);
	}

	public function testPanelRendersSourceLinksWhenSourceIsAvailable(): void
	{
		$logger = new SqlLogger([__FILE__]);
		$logger->debug('SELECT 1', ['duration' => 0.001]);

		$panel = (new QueryPanel($logger))->getPanel();

		self::assertStringContainsString('nettrine-dbal-backtrace', $panel);
	}

	public function testPanelDoesNotRenderSourceLinksWithoutSource(): void
	{
		$logger = new SqlLogger([]);
		$logger->debug('SELECT 1', ['duration' => 0.001]);

		$panel = (new QueryPanel($logger))->getPanel();

		self::assertStringNotContainsString('nettrine-dbal-backtrace', $panel);
	}

	public function testPanelDoesNotLeaveAnyOutputBufferOpen(): void
	{
		$level = ob_get_level();

		(new QueryPanel(new SqlLogger([])))->getPanel();

		self::assertSame($level, ob_get_level());
	}
}

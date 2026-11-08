<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kills the other sessions of the connection's database user - run by a deploy in a maintenance
 * window right before the migrations. DDL on a Galera cluster conflicts with the metadata locks
 * of the running application (a consumer in a long job, connections of the ProxySQL pool) and
 * can crash a node (mysql-wsrep, wsrep_handle_mdl_conflict).
 *
 * --wait=N first waits up to N seconds until no session of the user is busy (a running statement
 * or an open transaction), so that a consumer finishes its job instead of losing it half-way;
 * only the idle sessions are killed then. Without the PROCESS privilege open transactions are
 * not visible (information_schema.INNODB_TRX) and only running statements are waited for.
 *
 * KILL sent through ProxySQL does not reach the backend (ProxySQL takes it for the id of its own
 * session), so the command connects directly to the node the proxy writes to - on Galera it is
 * @@wsrep_node_address + @@port, asked through the proxy. Without Galera the connection itself
 * is used. A user may kill its own threads without any extra privilege.
 *
 * Killed in rounds: ProxySQL retries a killed autocommit statement on a new connection
 * (mysql-query_retries_on_failure), which would hold the locks again.
 */
#[AsCommand(name: 'dbal:kill-sessions', description: 'Kills the other sessions of the database user (before migrations)')]
class KillSessionsCommand extends Command
{
	private const KILL_ROUNDS = 3;

	public function __construct(
		private readonly Connection $connection,
	) {
		parent::__construct();
	}

	protected function configure(): void
	{
		$this->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait until the other sessions are idle (no running statement, no open transaction) before killing them', '0');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$wait = (int) $input->getOption('wait');
		$params = $this->connection->getParams();

		// SHOW VARIABLES, not @@wsrep_node_address - the variable does not exist without Galera
		$nodeAddress = (string) ($this->connection->fetchAssociative("SHOW GLOBAL VARIABLES LIKE 'wsrep_node_address'")['Value'] ?? '');
		if ($nodeAddress !== '') {
			$params['host'] = explode(':', $nodeAddress)[0];
			$params['port'] = (int) $this->connection->fetchOne('SELECT @@port');
			$this->connection->close();
			$connection = DriverManager::getConnection($params);
		} else {
			$connection = $this->connection;
		}
		$node = ($params['host'] ?? '?') . ':' . ($params['port'] ?? '?');

		$waited = 0;
		while ($waited < $wait && ($busy = $this->busySessions($connection)) !== []) {
			if ($waited === 0) {
				$output->writeln(sprintf('%s - %d busy session(s), waiting up to %d s', $node, count($busy), $wait));
			}
			sleep(1);
			$waited++;
		}
		if ($waited > 0) {
			$output->writeln(sprintf('%s - waited %d s%s', $node, $waited, $waited >= $wait ? ', still busy' : ''));
		}

		$killed = 0;
		for ($round = 1; $round <= self::KILL_ROUNDS; $round++) {
			if ($round > 1) {
				sleep(1);
			}
			$ids = $this->otherSessions($connection);
			if ($ids === []) {
				break;
			}
			foreach ($ids as $id) {
				try {
					$connection->executeStatement('KILL ' . (int) $id);
					$killed++;
				} catch (DBALException) {
					// The session ended in the meantime
				}
			}
		}

		$output->writeln(sprintf('%s - killed sessions: %d', $node, $killed));

		return self::SUCCESS;
	}

	/** @return list<int> */
	private function otherSessions(Connection $connection): array
	{
		return array_map('intval', $connection->fetchFirstColumn(
			"SELECT ID FROM information_schema.PROCESSLIST WHERE USER = SUBSTRING_INDEX(CURRENT_USER(), '@', 1) AND ID <> CONNECTION_ID()"
		));
	}

	/** @return list<int> */
	private function busySessions(Connection $connection): array
	{
		$own = "p.USER = SUBSTRING_INDEX(CURRENT_USER(), '@', 1) AND p.ID <> CONNECTION_ID()";
		try {
			$ids = $connection->fetchFirstColumn(
				"SELECT p.ID FROM information_schema.PROCESSLIST p
				LEFT JOIN information_schema.INNODB_TRX t ON t.trx_mysql_thread_id = p.ID
				WHERE $own AND (p.COMMAND <> 'Sleep' OR t.trx_id IS NOT NULL)"
			);
		} catch (DBALException) {
			// INNODB_TRX needs the PROCESS privilege
			$ids = $connection->fetchFirstColumn("SELECT p.ID FROM information_schema.PROCESSLIST p WHERE $own AND p.COMMAND <> 'Sleep'");
		}

		return array_map('intval', $ids);
	}
}

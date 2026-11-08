<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kills the other sessions of the connection's database user - run by a deploy in a maintenance
 * window right before the migrations. DDL on a Galera cluster conflicts with the metadata locks
 * of the running application (a consumer in a long job, connections of the ProxySQL pool) and
 * can crash a node (mysql-wsrep, wsrep_handle_mdl_conflict).
 *
 * KILL sent through ProxySQL does not reach the backend (ProxySQL takes it for the id of its own
 * session), so the command connects directly to the node the proxy writes to - on Galera it is
 * @@wsrep_node_address + @@port, asked through the proxy. Without Galera the connection itself
 * is used. A user may kill its own threads without any extra privilege.
 */
#[AsCommand(name: 'dbal:kill-sessions', description: 'Kills the other sessions of the database user (before migrations)')]
class KillSessionsCommand extends Command
{
	public function __construct(
		private readonly Connection $connection,
	) {
		parent::__construct();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
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

		$ids = $connection->fetchFirstColumn(
			"SELECT ID FROM information_schema.PROCESSLIST WHERE USER = SUBSTRING_INDEX(CURRENT_USER(), '@', 1) AND ID <> CONNECTION_ID()"
		);

		$killed = 0;
		foreach ($ids as $id) {
			try {
				$connection->executeStatement('KILL ' . (int) $id);
				$killed++;
			} catch (DBALException) {
				// The session ended in the meantime
			}
		}

		$output->writeln(sprintf('%s:%s - killed sessions: %d', $params['host'] ?? '?', $params['port'] ?? '?', $killed));

		return self::SUCCESS;
	}
}

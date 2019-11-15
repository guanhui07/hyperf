<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\DB;

use Hyperf\Pool\Exception\ConnectionException;
use Hyperf\Pool\Pool;
use PDO;
use PDOStatement;
use Psr\Container\ContainerInterface;

class PDOConnection extends AbstractConnection
{
    /**
     * @var PDO
     */
    protected $connection;

    /**
     * @var array
     */
    protected $config = [
        'driver' => 'pdo',
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'hyperf',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'fetch_mode' => PDO::FETCH_ASSOC,
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 3.0,
            'heartbeat' => -1,
            'max_idle_time' => 60.0,
        ],
        'options' => [
            PDO::ATTR_CASE => PDO::CASE_NATURAL,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ];

    /**
     * Current mysql database.
     * @var null|int
     */
    protected $database;

    public function __construct(ContainerInterface $container, Pool $pool, array $config)
    {
        parent::__construct($container, $pool);
        $this->config = array_replace_recursive($this->config, $config);
        $this->reconnect();
    }

    public function __call($name, $arguments)
    {
        return $this->connection->{$name}(...$arguments);
    }

    /**
     * Reconnect the connection.
     */
    public function reconnect(): bool
    {
        $username = $this->config['username'];
        $password = $this->config['password'];
        $dsn = $this->getHostDsn($this->config);
        try {
            $pdo = new \PDO($dsn, $username, $password, $this->config['options']);
        } catch (\Throwable $e) {
            throw new ConnectionException('Connection reconnect failed.:' . $e->getMessage());
        }

        $this->configureEncoding($pdo, $this->config);

        $this->configureTimezone($pdo, $this->config);

        $this->connection = $pdo;
        $this->lastUseTime = microtime(true);
        return true;
    }

    /**
     * Close the connection.
     */
    public function close(): bool
    {
        unset($this->connection);

        return true;
    }

    public function query(string $query, array $bindings = []): array
    {
        // For select statements, we'll simply execute the query and return an array
        // of the database result set. Each element in the array will be a single
        // row from the database table, and will either be an array or objects.
        $statement = $this->connection->prepare($query);

        $this->bindValues($statement, $bindings);

        $statement->execute();

        $fetchModel = $this->config['fetch_mode'];

        return $statement->fetchAll($fetchModel);
    }

    public function fetch(string $query, array $bindings = [])
    {
        $records = $this->query($query, $bindings);

        return array_shift($records);
    }

    public function execute(string $query, array $bindings = []): int
    {
        $statement = $this->connection->prepare($query);

        $this->bindValues($statement, $bindings);

        $statement->execute();

        return $statement->rowCount();
    }

    public function exec(string $sql): int
    {
        return $this->connection->exec($sql);
    }

    public function insert(string $query, array $bindings = []): int
    {
        $statement = $this->connection->prepare($query);

        $this->bindValues($statement, $bindings);

        $statement->execute();

        return (int) $this->connection->lastInsertId();
    }

    public function call(string $method, array $argument = [])
    {
        return $this->connection->{$method}(...$argument);
    }

    /**
     * Bind values to their parameters in the given statement.
     */
    protected function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }

    /**
     * Get the DSN string for a host / port configuration.
     *
     * @return string
     */
    protected function getHostDsn(array $config)
    {
        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        $database = $config['database'] ?? null;
        return isset($port)
            ? "mysql:host={$host};port={$port};dbname={$database}"
            : "mysql:host={$host};dbname={$database}";
    }

    /**
     * Set the connection character set and collation.
     *
     * @param \PDO $connection
     */
    protected function configureEncoding($connection, array $config)
    {
        if (! isset($config['charset'])) {
            return $connection;
        }
        $connection->prepare(
            "set names '{$config['charset']}'" . $this->getCollation($config)
        )->execute();
    }

    /**
     * Get the collation for the configuration.
     *
     * @return string
     */
    protected function getCollation(array $config)
    {
        return isset($config['collation']) ? " collate '{$config['collation']}'" : '';
    }

    /**
     * Set the timezone on the connection.
     *
     * @param \PDO $connection
     */
    protected function configureTimezone($connection, array $config)
    {
        if (isset($config['timezone'])) {
            $connection->prepare('set time_zone="' . $config['timezone'] . '"')->execute();
        }
    }

}

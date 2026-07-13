<?php
declare(strict_types=1);

/**
 * Test-only fault injector for atomicity / rollback tests. FailingPdo is a PDO subclass whose prepared
 * statements THROW when the SQL contains a target substring (usually a table name), letting a test simulate a
 * mid-transaction failure without touching production code. It mirrors CountingPdo (tests/counting_pdo.php):
 * both install a custom PDOStatement via ATTR_STATEMENT_CLASS — one counts executions, this one fails a chosen
 * one. Loaded once by tests/run.php before the case glob.
 *
 * Use with_failing_pdo('some_table', fn) to run a callback with the container PDO swapped for a FailingPdo that
 * throws on any statement whose SQL contains 'some_table'. Services/repos are auto-wired transient, so resolve
 * them FRESH inside the callback to pick up the swapped connection. The original PDO is restored in finally.
 *
 * Power-proof note: a rollBack() is backstopped by MySQL's auto-rollback on connection close, so to prove an
 * atomicity test bites you must flip the OWNING layer's rollBack() to commit() (persist the partial write) —
 * deleting the rollback alone leaves the disconnect to clean up and the test stays green. And mind which layer
 * owns the transaction (e.g. TicketService::createTicket owns it; TicketRepository defers when already in one).
 */

class FailingStatement extends PDOStatement
{
    /** @var FailingPdo */
    protected $owner;

    protected function __construct(FailingPdo $owner)
    {
        $this->owner = $owner;
    }

    public function execute(?array $params = null): bool
    {
        if ($this->owner->failOnSql !== '' && str_contains($this->queryString, $this->owner->failOnSql)) {
            throw new RuntimeException('FailingPdo: injected statement failure on SQL containing "' . $this->owner->failOnSql . '"');
        }

        return parent::execute($params);
    }
}

class FailingPdo extends PDO
{
    public string $failOnSql = '';

    /** @param array<int, mixed>|null $options */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [FailingStatement::class, [$this]]);
    }
}

/** Run $fn with the container PDO swapped for one that throws on any statement whose SQL contains $failOnSql. */
function with_failing_pdo(string $failOnSql, callable $fn): void
{
    $container = tvm_container();
    $original = $container->get(PDO::class);

    $db = $container->get('config')['db'];
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
    $failing = new FailingPdo($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $failing->failOnSql = $failOnSql;

    $container->instance(PDO::class, $failing);
    try {
        $fn();
    } finally {
        $container->instance(PDO::class, $original); // restore for every other test
    }
}

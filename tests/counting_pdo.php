<?php
declare(strict_types=1);

/**
 * Test-only query counter. CountingPdo is a PDO subclass that tallies every statement EXECUTION — prepared
 * execute(), query(), and exec() — so N+1 regressions can be asserted deterministically (query count, not
 * timing, which would be flaky). Loaded once by tests/run.php before the case glob.
 *
 * Counting must happen at execute() (not prepare()): a loop-style N+1 prepares once and executes N times, so
 * counting prepare() would miss it entirely. Prepared statements become CountingStatement via
 * ATTR_STATEMENT_CLASS; query()/exec() run in native code (they do NOT call the PHP execute() override) so
 * they are tallied in their own overrides — no double counting.
 *
 * Use count_queries(fn) to measure how many queries a callback issues. It swaps the container's PDO for a
 * fresh CountingPdo (services are auto-wired transient, so they pick it up), runs the callback, and restores
 * the original PDO in finally.
 */

class CountingStatement extends PDOStatement
{
    /** @var CountingPdo */
    protected $owner;

    protected function __construct(CountingPdo $owner)
    {
        $this->owner = $owner;
    }

    public function execute(?array $params = null): bool
    {
        $this->owner->count++;

        return parent::execute($params);
    }
}

class CountingPdo extends PDO
{
    public int $count = 0;

    /** @param array<int, mixed>|null $options */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, [$this]]);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->count++;

        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->count++;

        return parent::exec($statement);
    }
}

/**
 * Release the per-day named locks (ticket-number-YYYYMMDD / work-order-number-YYYYMMDD) a disposable test
 * connection may still hold. createTicket's nested path — a caller owning the transaction (guest-convert) — skips
 * releaseNamedLock and relies on the CONNECTION ENDING to auto-release the GET_LOCK. That is true in a web request
 * but not for a swapped test connection that a cached service keeps alive: it goes on holding the lock and blocks
 * the next test's GET_LOCK ("ระบบกำลังสร้างเลข Ticket"). MariaDB 10.4 has no RELEASE_ALL_LOCKS, so release the known
 * names for a small date window. RELEASE_LOCK on a lock this connection does not hold is a harmless no-op.
 */
function test_release_stale_named_locks(PDO $connection): void
{
    foreach (['ticket-number-', 'work-order-number-'] as $prefix) {
        for ($day = -1; $day <= 1; $day++) {
            try {
                $connection->prepare('SELECT RELEASE_LOCK(?)')->execute([$prefix . date('Ymd', strtotime("{$day} day"))]);
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }
}

/** Run $fn with the container PDO swapped for a fresh CountingPdo; return the number of queries it issued. */
function count_queries(callable $fn): int
{
    $container = tvm_container();
    $original = $container->get(PDO::class);

    $db = $container->get('config')['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );
    $counting = new CountingPdo($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $container->instance(PDO::class, $counting);
    $counting->count = 0; // ignore any setup queries; measure only the callback
    try {
        $fn();

        return $counting->count;
    } finally {
        test_release_stale_named_locks($counting); // don't let a lock on the disposable connection block later tests
        $container->instance(PDO::class, $original);
    }
}

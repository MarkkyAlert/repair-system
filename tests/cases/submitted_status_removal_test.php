<?php

declare(strict_types=1);

/**
 * Parse tickets.status from the fresh-install schema without treating the expected default as the parser oracle.
 * This lets the test fail on an assertion (not a setup error) if somebody restores the old submitted default.
 *
 * @return array{statuses: list<string>, default: string}
 */
function ssr_schema_ticket_status_contract(): array
{
    $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
    if (!preg_match('/CREATE TABLE tickets \((.*?)\n\) ENGINE=/s', $schema, $table)
        || !preg_match("/\n\s*status ENUM\(([^)]*)\)\s*NOT NULL DEFAULT '([^']+)'/", $table[1], $match)) {
        throw new RuntimeException('could not locate tickets.status in schema.sql');
    }

    preg_match_all("/'([a-z_]+)'/", $match[1], $values);

    return ['statuses' => $values[1], 'default' => $match[2]];
}

function ssr_container(): App\Core\Container
{
    return $GLOBALS['__container'];
}

test('submitted-removal: fresh schema and PHP status contract start directly at pending_approval', function (): void {
    $contract = ssr_schema_ticket_status_contract();
    $schemaStatuses = $contract['statuses'];

    assert_same('pending_approval', $contract['default'], 'the fresh-install DB default is the first real workflow state');
    assert_same('pending_approval', $schemaStatuses[0], 'the DB default is the first real workflow state');
    assert_false(in_array('submitted', $schemaStatuses, true), 'the unreachable submitted state is absent');
    assert_true(in_array('closed', $schemaStatuses, true), 'closed remains supported for historical/demo records');
    assert_same($schemaStatuses, ticket_status_values(), 'schema and PHP status contract remain aligned');
});

test('submitted-removal: omitting status in the test database defaults to actionable pending_approval', function (): void {
    $database = ssr_container()->get(PDO::class);
    $databaseName = (string) $database->query('SELECT DATABASE()')->fetchColumn();
    $column = $database->query(
        "SELECT COLUMN_TYPE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = " . $database->quote($databaseName) . "
           AND TABLE_NAME = 'tickets'
           AND COLUMN_NAME = 'status'"
    )->fetch(PDO::FETCH_ASSOC);

    assert_true(is_array($column), 'the test database exposes tickets.status metadata');
    assert_same('pending_approval', trim((string) $column['COLUMN_DEFAULT'], "'"), 'live test schema default matches fresh installs');
    assert_false(str_contains((string) $column['COLUMN_TYPE'], "'submitted'"), 'the live test enum no longer accepts submitted');

    $ticketNo = 'SSR-' . bin2hex(random_bytes(6));
    $ticketId = 0;
    try {
        $database->prepare(
            "INSERT INTO tickets (
                ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id
             ) VALUES (?, 'schema default probe', 'x', 1, 1, 1, 1)"
        )->execute([$ticketNo]);
        $ticketId = (int) $database->lastInsertId();

        $row = $database->query(
            "SELECT status, approval_status FROM tickets WHERE id = $ticketId"
        )->fetch(PDO::FETCH_ASSOC);
        assert_same('pending_approval', (string) $row['status'], 'an omitted status cannot create a dead state');
        assert_same('pending', (string) $row['approval_status'], 'the two status dimensions start aligned');
    } finally {
        if ($ticketId > 0) {
            $database->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
    }
});

test('submitted-removal: upgrade maps legacy rows before shrinking enum and preserves closed', function (): void {
    $config = ssr_container()->get('config')['db'];
    $root = ssr_container()->get(PDO::class);
    $scratch = 'repair_system_submitted_migration_test';
    $connect = static fn (string $database): PDO => new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
        (string) $config['username'],
        (string) $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    try {
        $root->exec("DROP DATABASE IF EXISTS `{$scratch}`");
        $root->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4");
        $database = $connect($scratch);
        $database->exec(
            "CREATE TABLE tickets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                status ENUM(
                    'submitted','pending_approval','approved','assigned','accepted',
                    'in_progress','resolved','completed','rejected','cancelled','closed'
                ) NOT NULL DEFAULT 'submitted',
                approval_status ENUM('not_required','pending','approved','rejected') NOT NULL DEFAULT 'pending',
                approved_at DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB"
        );
        $database->exec(
            "INSERT INTO tickets (status, approval_status, approved_at) VALUES
                ('submitted', 'approved', '2026-01-01 10:00:00'),
                ('closed', 'approved', '2026-01-02 10:00:00')"
        );

        $database->exec((string) file_get_contents(
            BASE_PATH . '/database/upgrades/migrate_drop_submitted_status.sql'
        ));

        $legacy = $database->query(
            'SELECT status, approval_status, approved_at FROM tickets WHERE id = 1'
        )->fetch(PDO::FETCH_ASSOC);
        assert_same('pending_approval', (string) $legacy['status'], 'a legacy submitted row enters the real manager queue');
        assert_same('pending', (string) $legacy['approval_status'], 'legacy approval dimension is aligned');
        assert_true($legacy['approved_at'] === null, 'a pending legacy row cannot retain a false approval timestamp');

        $closed = $database->query('SELECT status, approval_status FROM tickets WHERE id = 2')
            ->fetch(PDO::FETCH_ASSOC);
        assert_same('closed', (string) $closed['status'], 'the supported historical closed state is untouched');
        assert_same('approved', (string) $closed['approval_status'], 'closed approval history is untouched');

        $column = $database->query(
            "SELECT COLUMN_TYPE, COLUMN_DEFAULT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = '{$scratch}'
               AND TABLE_NAME = 'tickets'
               AND COLUMN_NAME = 'status'"
        )->fetch(PDO::FETCH_ASSOC);
        assert_same('pending_approval', trim((string) $column['COLUMN_DEFAULT'], "'"), 'upgraded DB has the new default');
        assert_false(str_contains((string) $column['COLUMN_TYPE'], "'submitted'"), 'upgraded enum rejects submitted');
        assert_true(str_contains((string) $column['COLUMN_TYPE'], "'closed'"), 'upgraded enum retains closed');
    } finally {
        $root->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    }
});

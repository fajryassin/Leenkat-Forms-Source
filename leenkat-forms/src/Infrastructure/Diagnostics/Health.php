<?php
declare(strict_types=1);
namespace LeenkatForms\Infrastructure\Diagnostics;
use LeenkatForms\Infrastructure\Database\Migrations;
final class Health {
    private static function indexes(): array {
        global $wpdb;
        $required = [
            'forms' => ['PRIMARY', 'uuid'],
            'form_versions' => ['PRIMARY', 'form_version'],
            'entries' => ['PRIMARY', 'uuid', 'form_submission', 'form_status_id'],
            'entry_values' => ['PRIMARY', 'entry_field_row'],
            'entry_files' => ['PRIMARY', 'entry_id'],
            'deliveries' => ['PRIMARY', 'idempotency_key', 'status_due'],
        ];
        $result = [];
        foreach ($required as $suffix => $names) {
            $table = $wpdb->prefix . 'lkf_' . $suffix;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) { $result[$suffix] = false; continue; }
            // Table name is generated from a fixed allowlist, never from user input.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            $keys = $wpdb->get_col('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`', 2);
            $result[$suffix] = empty(array_diff($names, $keys));
        }
        return $result;
    }
    private static function lifecycleTest(): bool {
        $states = \LeenkatForms\Submissions\EntryStatus::all();
        return count($states) === count(array_unique($states))
            && count($states) === 6
            && \LeenkatForms\Submissions\EntryStatus::canTransition('draft', 'complete')
            && !\LeenkatForms\Submissions\EntryStatus::canTransition('complete', 'draft')
            && !\LeenkatForms\Submissions\EntryStatus::canTransition('invalid', 'complete');
    }
    private static function runtime(): array {
        global $wpdb;
        return [
            'PHP 8.3+' => version_compare(PHP_VERSION, '8.3', '>='),
            'JSON extension' => extension_loaded('json'),
            'OpenSSL or Sodium' => extension_loaded('openssl') || extension_loaded('sodium'),
            'MySQL connection' => $wpdb->check_connection(false),
        ];
    }
    private static function foundationAudit(): array {
        global $wpdb;
        $admin = get_role('administrator');
        $editor = get_role('editor');
        $caps = \LeenkatForms\Security\Capabilities::all();
        $admin_caps = $admin !== null && count(array_filter($caps, static fn(string $cap): bool => $admin->has_cap($cap))) === count($caps);
        $editor_caps = $editor === null ? null : !array_filter($caps, static fn(string $cap): bool => $editor->has_cap($cap));
        $lock = get_option('lkf_migration_lock', false);
        $table_engine = [];
        foreach (Migrations::tables() as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) { $table_engine[$table] = 'Missing'; continue; }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)), ARRAY_A);
            $table_engine[$table] = strtoupper((string)($row['Engine'] ?? 'Unknown'));
        }
        return [
            'Administrator has all Leenkat Forms capabilities' => $admin_caps ? 'Passed' : 'Check required',
            'Editor has no Leenkat Forms capabilities' => $editor_caps === null ? 'Not applicable' : ($editor_caps ? 'Passed' : 'Check required'),
            'Migration lock' => $lock === false ? 'Not held' : 'Held (review required)',
            'Stored schema matches code' => (int)get_option('lkf_db_version', 0) === Migrations::VERSION ? 'Passed' : 'Check required',
            'Registered Leenkat Forms REST routes' => self::publicRestRoutes(),
            'Database engines' => $table_engine,
        ];
    }
    private static function publicRestRoutes(): string {
        // Inspect the live REST server rather than treating a constant as proof.
        $server = rest_get_server();
        $routes = $server->get_routes();
        $public = [];
        foreach ($routes as $route => $handlers) {
            if (!str_starts_with($route, '/leenkat-forms/')) { continue; }
            foreach ($handlers as $handler) {
                if (!is_array($handler) || !isset($handler['methods'])) { continue; }
                $public[] = $route;
                break;
            }
        }
        return $public === [] ? 'No routes registered' : 'Review: ' . implode(', ', array_unique($public));
    }
    public static function snapshot(): array {
        global $wpdb;
        $tables = [];
        foreach (Migrations::tables() as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale.
            $tables[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        }
        $deliveries = [];
        $deliveryTable = $wpdb->prefix . 'lkf_deliveries';
        if (!empty($tables[$deliveryTable])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom plugin tables and transaction control have no equivalent WordPress object API; values are prepared and access is gated by the caller. Fresh custom-table state is required for locking, ownership checks, exports and current admin results; a cache could be stale. Identifiers are fixed plugin table names from the trusted WordPress prefix; dynamic clauses are internal allowlists or already-prepared fragments, never raw request SQL.
            foreach ($wpdb->get_results("SELECT status,COUNT(*) AS total FROM `{$deliveryTable}` GROUP BY status") ?: [] as $row) { $deliveries[(string)$row->status] = (int)$row->total; }
        }
        return ['version' => LEENKAT_FORMS_VERSION, 'php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'), 'schema' => (int)get_option('lkf_db_version',0), 'tables' => $tables, 'error' => (string)get_option('lkf_migration_error',''), 'contracts' => ['ModuleInterface','FieldTypeInterface','IntegrationInterface'], 'definition_contract_tests' => \LeenkatForms\Forms\DefinitionContractTests::run(), 'definition_test_cases' => \LeenkatForms\Forms\DefinitionContractTests::cases(), 'lifecycle_test' => self::lifecycleTest(), 'runtime' => self::runtime(), 'schema_contract' => \LeenkatForms\Forms\DefinitionValidator::SCHEMA_VERSION, 'entry_states' => count(\LeenkatForms\Submissions\EntryStatus::all()), 'public_read_allowed' => \LeenkatForms\Security\SubmissionPolicy::PUBLIC_READ_ALLOWED, 'table_indexes' => self::indexes(), 'foundation_audit' => self::foundationAudit(), 'deliveries' => $deliveries];
    }
}

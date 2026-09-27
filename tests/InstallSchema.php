<?php

namespace Tests;

use Illuminate\Support\Facades\DB;

/**
 * Builds the panel's real tables in the test database (in-memory SQLite, see
 * phpunit.xml) from database/install.sql.
 *
 * The schema of this panel lives in install.sql, not in migrations, so a test
 * that needs tables takes them from the same file a real install is built
 * from. Only what SQLite cannot read is dropped: engine and charset clauses,
 * comments, secondary indexes and MySQL column types (mapped to SQLite's
 * affinities). Every column, default and NOT NULL stays as it is.
 */
trait InstallSchema
{
    protected function createInstallTables(array $tables): void
    {
        $sql = file_get_contents(base_path('database/install.sql'));
        foreach ($tables as $table) {
            if (!preg_match('/CREATE TABLE `' . preg_quote($table, '/') . '` \((.*?)\n\)[^;]*;/s', $sql, $m)) {
                throw new \RuntimeException("{$table} is not in install.sql");
            }
            $columns = [];
            foreach (explode("\n", $m[1]) as $line) {
                $line = trim(rtrim(trim($line), ','));
                if ($line === '' || !str_starts_with($line, '`')) {
                    continue; // PRIMARY KEY / KEY / UNIQUE KEY / CONSTRAINT lines
                }
                if (!preg_match('/^`([^`]+)`\s+([a-z]+)(\([^)]*\))?(.*)$/i', $line, $c)) {
                    continue;
                }
                [, $name, $type, , $rest] = $c;
                $rest = preg_replace("/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.|'')*'/i", '', $rest);
                $rest = preg_replace('/\s+(unsigned|zerofill|AUTO_INCREMENT|CHARACTER SET \w+|COLLATE \w+|ON UPDATE CURRENT_TIMESTAMP(\(\))?)/i', '', $rest);
                if ($name === 'id' && stripos($line, 'AUTO_INCREMENT') !== false) {
                    $columns[] = '"id" INTEGER PRIMARY KEY AUTOINCREMENT';
                    continue;
                }
                $type = strtolower($type);
                if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint'], true)) {
                    $affinity = 'INTEGER';
                } elseif (in_array($type, ['decimal', 'float', 'double'], true)) {
                    $affinity = 'NUMERIC';
                } else {
                    $affinity = 'TEXT';
                }
                $columns[] = '"' . $name . '" ' . $affinity . ' ' . trim($rest);
            }
            DB::statement('DROP TABLE IF EXISTS "' . $table . '"');
            DB::statement('CREATE TABLE "' . $table . '" (' . implode(', ', $columns) . ')');
        }
    }
}

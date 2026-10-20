<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Runs the Node project's .sql migrations (database/sql/*.sql) in filename order.
 * This is a PHP port of backend/db/migrate.js: it strips DELIMITER lines and
 * understands the "-- @add-column <table> <column> <definition>" directive.
 */
return new class extends Migration
{
    public function up(): void
    {
        $pdo = DB::connection()->getPdo();
        $files = glob(database_path('sql/*.sql'));
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $sql = str_replace("\r\n", "\n", file_get_contents($file));
            $sql = preg_replace('/^[ \t]*DELIMITER[ \t]+\$\$[ \t]*$/im', '', $sql);
            $sql = preg_replace('/^[ \t]*DELIMITER[ \t]+;[ \t]*$/im', '', $sql);
            $sql = str_replace('END$$', 'END;', $sql);

            $chunk = [];
            $flush = function () use (&$chunk, $pdo) {
                $part = implode("\n", $chunk);
                $chunk = [];
                if (trim(preg_replace('/--.*$/m', '', $part)) !== '') {
                    $pdo->exec($part);
                }
            };

            foreach (explode("\n", $sql) as $line) {
                if (preg_match('/^[ \t]*--[ \t]*@add-column[ \t]+(\w+)[ \t]+(\w+)[ \t]+(.+?)[ \t]*;?[ \t]*$/', $line, $m)) {
                    $flush();
                    [, $table, $column, $definition] = $m;
                    $exists = DB::selectOne(
                        'SELECT COUNT(*) AS c FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                        [$table, $column]
                    )->c > 0;
                    if (! $exists) {
                        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                    }
                } else {
                    $chunk[] = $line;
                }
            }
            $flush();
        }
    }

    public function down(): void
    {
        // Use `php artisan migrate:fresh`, which drops every table.
    }
};

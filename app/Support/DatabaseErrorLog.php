<?php

namespace App\Support;

use Illuminate\Database\QueryException;

// Database error log line without personal data: first driver line only, quoted values masked except object names.
final class DatabaseErrorLog
{
    private const OBJECT_KINDS = ['column', 'relation', 'table', 'constraint', 'index', 'schema', 'type', 'function', 'sequence', 'database', 'role'];

    public static function message(QueryException $e): string
    {
        return 'Database error: '.self::redact($e->getPrevious()?->getMessage() ?? 'unknown');
    }

    public static function redact(string $driverMessage): string
    {
        $firstLine = strtok($driverMessage, "\n") ?: '';

        return preg_replace_callback('/(\b[A-Za-z_]+\s+)?"[^"]*"/', function ($m) {
            $kind = strtolower(trim($m[1] ?? ''));

            return in_array($kind, self::OBJECT_KINDS, true) ? $m[0] : ($m[1] ?? '').'"?"';
        }, $firstLine);
    }
}

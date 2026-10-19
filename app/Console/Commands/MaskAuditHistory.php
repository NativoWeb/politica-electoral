<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditDataMasker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/*
 * One-off: applies the config/audit.php masking to audit_logs rows written before it existed.
 * Idempotent (already-masked rows are left untouched). audit_logs is append-only for PUBLIC, so this
 * relies on the application DB user being allowed to UPDATE it. Run it ONCE in production, only with
 * Marly's authorization and after a backup, always starting with --dry-run.
 */
class MaskAuditHistory extends Command
{
    protected $signature = 'audit:mask-history {--dry-run : Count the rows that would change without writing}';

    protected $description = 'Mask personal data in existing audit_logs rows (Ley 1581)';

    private const COLUMNS = ['metadata', 'before_state', 'after_state'];

    public function handle(AuditDataMasker $masker): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $scanned = 0;
        $changed = 0;
        $failed = 0;

        DB::table('audit_logs')
            ->select(['id', ...self::COLUMNS])
            ->chunkById(500, function ($rows) use ($masker, $dryRun, &$scanned, &$changed, &$failed) {
                $updates = [];
                foreach ($rows as $row) {
                    $scanned++;
                    $values = [];
                    foreach (self::COLUMNS as $column) {
                        $original = is_string($row->{$column}) ? $this->decode($row->{$column}) : null;
                        if (! is_array($original)) {
                            continue;
                        }
                        $masked = $masker->mask($original);
                        if ($masked === $original) {
                            continue;
                        }
                        $encoded = json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                        if ($encoded === false) {
                            $this->error("Row {$row->id} skipped: {$column} could not be re-encoded (".json_last_error_msg().').');
                            $failed++;
                            $values = [];

                            continue 2;
                        }
                        $values[$column] = $encoded;
                    }
                    if ($values !== []) {
                        $updates[$row->id] = $values;
                    }
                }

                $changed += count($updates);
                if ($dryRun || $updates === []) {
                    return;
                }

                DB::transaction(function () use ($updates) {
                    foreach ($updates as $id => $values) {
                        DB::table('audit_logs')->where('id', $id)->update($values);
                    }
                });
            }, 'id');

        $verb = $dryRun ? 'would be masked (dry run, nothing written)' : 'masked';
        $this->info("Scanned {$scanned} audit_logs rows; {$changed} {$verb}.");
        if ($failed > 0) {
            $this->error("{$failed} rows were left untouched because of encoding errors.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // Objects become arrays so they can be masked, except empty ones, which stay {} when written back.
    private function decode(string $json): mixed
    {
        return $this->objectsToArrays(json_decode($json));
    }

    private function objectsToArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            if (get_object_vars($value) === []) {
                return $value;
            }
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(fn ($item) => $this->objectsToArrays($item), $value) : $value;
    }
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceRecord extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'source_file_id', 'import_job_id', 'sheet_name', 'row_number',
        'cell_reference', 'raw_value', 'target_entity', 'target_id',
        'target_field', 'transformation_applied',
    ];

    protected $casts = [
        'row_number' => 'integer',
    ];

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class);
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}

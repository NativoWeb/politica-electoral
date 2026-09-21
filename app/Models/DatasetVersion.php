<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'dataset_type', 'source_file_id', 'import_job_id',
        'valid_from', 'valid_to', 'data_cutoff_date', 'notes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'data_cutoff_date' => 'date',
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

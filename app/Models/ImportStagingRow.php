<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportStagingRow extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'import_job_id', 'source_row_number', 'raw_data',
        'normalized_data', 'matched_entities', 'status', 'issues',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'normalized_data' => 'array',
        'matched_entities' => 'array',
        'issues' => 'array',
        'source_row_number' => 'integer',
    ];

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}

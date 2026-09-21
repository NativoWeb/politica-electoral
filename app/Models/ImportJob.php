<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'source_file_id', 'mapping_template_id', 'status', 'dataset_type',
        'sheet_name', 'profiling_result', 'total_rows', 'valid_rows',
        'error_rows', 'warning_rows', 'issues', 'approved_by', 'approved_at',
        'committed_by', 'committed_at', 'rollback_reason', 'created_by',
    ];

    protected $casts = [
        'profiling_result' => 'array',
        'issues' => 'array',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'error_rows' => 'integer',
        'warning_rows' => 'integer',
        'approved_at' => 'datetime',
        'committed_at' => 'datetime',
    ];

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(SourceFile::class);
    }

    public function mappingTemplate(): BelongsTo
    {
        return $this->belongsTo(MappingTemplate::class);
    }

    public function stagingRows(): HasMany
    {
        return $this->hasMany(ImportStagingRow::class);
    }

    public function sourceRecords(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

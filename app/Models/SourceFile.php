<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceFile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'original_name', 'storage_path', 'mime_type', 'size_bytes',
        'sha256_hash', 'file_type', 'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function importJobs(): HasMany
    {
        return $this->hasMany(ImportJob::class);
    }

    public function sourceRecords(): HasMany
    {
        return $this->hasMany(SourceRecord::class);
    }
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'document_id', 'version_number', 'storage_path',
        'sha256_hash', 'size_bytes', 'created_by', 'change_note',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

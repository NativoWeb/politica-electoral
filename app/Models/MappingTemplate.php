<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MappingTemplate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'dataset_type', 'column_mappings', 'header_row',
        'data_start_row', 'version', 'is_active', 'created_by',
    ];

    protected $casts = [
        'column_mappings' => 'array',
        'header_row' => 'integer',
        'data_start_row' => 'integer',
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

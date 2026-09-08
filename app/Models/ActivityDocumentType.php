<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityDocumentType extends Model
{
    use HasFactory;

    protected $fillable = [
        'activity_id',
        'document_type_id',
        'target',
        'is_required',
        'validity_days',
        'description',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'validity_days' => 'integer',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }
}

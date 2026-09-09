<?php

namespace App\Models;

use App\Domain\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Rapor PDF'i (ana dosya) hâlâ FireSuppressionReport::file_path'te —
// bu model SADECE ek dosyalar (fotoğraf, ek belge vb.) için.
class FireSuppressionReportFile extends Model
{
    use HasFactory;

    public const TYPES = ['fotograf', 'ek_belge', 'diger'];

    protected $fillable = [
        'tenant_id',
        'report_id',
        'file_type',
        'file_path',
        'file_name',
        'file_size',
        'description',
        'uploaded_by_user_id',
    ];

    protected $appends = ['file_url'];

    protected static function booted(): void
    {
        static::addGlobalScope(
            app(TenantScope::class)
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(FireSuppressionReport::class, 'report_id');
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return url('/storage/' . ltrim($this->file_path, '/'));
    }
}

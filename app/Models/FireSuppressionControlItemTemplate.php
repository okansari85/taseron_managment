<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

// Tenant'a bağlı DEĞİL — tüm tenant'lar aynı standart kategori bazlı kontrol
// maddesi listesinden başlar. Bir rapor oluşturulurken bu şablon
// FireSuppressionReportControlItem'a kopyalanır.
class FireSuppressionControlItemTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'code',
        'section',
        'title',
        'sort_order',
    ];
}

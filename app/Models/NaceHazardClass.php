<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NaceHazardClass extends Model
{
    use HasFactory;

    // Resmi "İş Sağlığı ve Güvenliğine İlişkin İşyeri Tehlike Sınıfları Tebliği" referans
    // listesi — tenant'tan bağımsız, sistem geneli sabit bir tablo (sgk_occupation_codes
    // ile aynı desen: bkz. proje dokümanı). Tenant scope YOK, kasıtlı.
    protected $fillable = [
        'nace_code',
        'activity_name',
        'hazard_class',
    ];
}

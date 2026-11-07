<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// pktakip: Kullanıcı Profili sayfasının bilgileri (avatar, telefon, meslek, sınıf, uzmanlık, kuruluş).
class PkUserProfile extends Model
{
    protected $fillable = ['user_id', 'avatar', 'phone', 'profession', 'expert_class', 'specialty', 'employer'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Alan türü: operational (operasyonel: depo, bina…; firma ister) ya da common (ortak: yemekhane, tuvalet…; firmasız).
// tenant_id boşsa herkes için hazır tür, doluysa o hesabın eklediği tür (tenant kapsamı serviste; hazır türler herkese görünür).
class PkAreaType extends Model
{
    public const OPERATIONAL = 'operational';

    public const COMMON = 'common';

    protected $fillable = ['tenant_id', 'name', 'kind', 'sort_order'];
}

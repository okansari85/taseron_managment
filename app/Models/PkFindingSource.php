<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Bulgunun görüldüğü rapor: source_type 'inspection' (ekipman kontrolü) ya da 'installation_report' (tesisat raporu).
class PkFindingSource extends Model
{
    protected $fillable = ['pk_finding_id', 'source_type', 'source_id', 'control_date'];

    protected $casts = ['control_date' => 'date:Y-m-d'];
}

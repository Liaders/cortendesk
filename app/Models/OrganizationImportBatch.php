<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationImportBatch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rows' => 'array', 'preview' => 'array', 'result' => 'array', 'expires_at' => 'datetime', 'applied_at' => 'datetime'];
    }
}

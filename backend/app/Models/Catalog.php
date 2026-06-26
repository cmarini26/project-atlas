<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Catalog extends Model
{
    use BelongsToCompany, HasUlids;

    protected $fillable = [
        'company_id',
        'name',
        'type',
        'item_schema',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'item_schema' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}

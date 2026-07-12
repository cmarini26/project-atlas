<?php

namespace App\Models;

use App\Domain\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'feedback';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'user_id',
        'score',
        'comment',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'context' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentType extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'has_group' => 'boolean',
            'is_basic' => 'boolean',
            'validity_months' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worker extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hired_at' => 'date',
            'is_active' => 'boolean',
            'grade' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(WorkerDocument::class);
    }

    public function fullName(): string
    {
        return trim($this->last_name.' '.$this->first_name.' '.($this->middle_name ?? ''));
    }

    public function shortName(): string
    {
        $initials = mb_substr($this->first_name, 0, 1).'.'.($this->middle_name ? mb_substr($this->middle_name, 0, 1).'.' : '');

        return $this->last_name.' '.$initials;
    }
}

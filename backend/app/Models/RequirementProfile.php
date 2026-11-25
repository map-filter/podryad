<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementProfile extends Model
{
    protected $guarded = ['id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequirementProfileItem::class);
    }

    /**
     * @return array<int, array{type: DocumentType, min_group: int|null}>
     */
    public function requirements(): array
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with('type')->get();

        return $items->sortBy(fn ($i) => $i->type->sort ?? 0)->values()->map(fn (RequirementProfileItem $i) => [
            'type' => $i->type,
            'min_group' => $i->min_group ? (int) $i->min_group : null,
        ])->all();
    }
}

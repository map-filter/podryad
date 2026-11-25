<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'notify_thresholds' => 'array',
            'notify_email_enabled' => 'boolean',
            'notify_telegram_enabled' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    public function documentTypes(): HasMany
    {
        return $this->hasMany(DocumentType::class);
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(RequirementProfile::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(AdmissionRequest::class);
    }

    /** @return int[] */
    public function thresholds(): array
    {
        return \App\Services\Readiness::normalizeThresholds($this->notify_thresholds ?? []);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    public const HEAD = 'head';

    public const SAFETY = 'safety';

    public const FOREMAN = 'foreman';

    public const CUSTOMER = 'customer';

    public const ROLES = [
        self::HEAD => 'Руководитель',
        self::SAFETY => 'Специалист по ОТ',
        self::FOREMAN => 'Мастер',
        self::CUSTOMER => 'Представитель заказчика',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isCustomer(): bool
    {
        return $this->role === self::CUSTOMER;
    }

    public function canEdit(): bool
    {
        return in_array($this->role, [self::HEAD, self::SAFETY], true);
    }

    public function canManage(): bool
    {
        return $this->role === self::HEAD;
    }

    public function canSign(): bool
    {
        return in_array($this->role, [self::HEAD, self::SAFETY], true);
    }
}

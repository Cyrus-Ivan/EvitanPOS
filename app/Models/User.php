<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Exceptions\CannotDeleteOwnerException;
use App\Models\Concerns\HasUuidPrimaryKey;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuidPrimaryKey, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Guard the "Owner can never be archived or deleted" invariant at the model
     * layer, so no code path (soft or force delete) can remove the Owner.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $user): void {
            if ($user->role === UserRole::Owner) {
                throw new CannotDeleteOwnerException;
            }
        });
    }

    /**
     * @return HasMany<LoginHistory, $this>
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isCashier(): bool
    {
        return $this->role === UserRole::Cashier;
    }

    /**
     * Whether this user's role clears a minimum-role requirement, honouring the
     * Owner > Admin > Cashier hierarchy.
     */
    public function hasRoleAtLeast(UserRole $role): bool
    {
        return $this->role->atLeast($role);
    }
}

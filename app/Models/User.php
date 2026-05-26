<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMINISTRATOR = 'administrator';
    public const ROLE_ADMIN         = 'admin';
    public const ROLE_USER          = 'user';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ───────── Relationships ─────────
    public function createdAccounts(): HasMany
    {
        return $this->hasMany(Mt5Account::class, 'created_by');
    }

    /** Accounts explicitly assigned to a user-role viewer */
    public function assignedAccounts(): BelongsToMany
    {
        return $this->belongsToMany(Mt5Account::class, 'mt5_account_user')->withTimestamps();
    }

    /** Who created this user (null if seeded / self-registered) */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Users this person created (admins manage their sub-users) */
    public function managedUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    // ───────── Role helpers ─────────
    public function isAdministrator(): bool { return $this->role === self::ROLE_ADMINISTRATOR; }
    public function isAdmin(): bool         { return $this->role === self::ROLE_ADMIN; }
    public function isUser(): bool          { return $this->role === self::ROLE_USER; }

    public function canCreateAccounts(): bool
    {
        return $this->isAdministrator() || $this->isAdmin();
    }

    public function canManageUsers(): bool
    {
        return $this->isAdministrator() || $this->isAdmin();
    }

    /**
     * Can $this user manage (edit/delete) the given $other user?
     *   administrator → anyone except themselves (for delete)
     *   admin         → only users they created, and only 'user' role
     *   user          → no
     */
    public function canManageUser(User $other): bool
    {
        if ($this->isAdministrator()) {
            return $this->id !== $other->id;
        }
        if ($this->isAdmin()) {
            return $other->role === self::ROLE_USER && (int) $other->created_by === $this->id;
        }
        return false;
    }

    /**
     * Roles this user is allowed to assign when creating/editing users.
     */
    public function assignableRoles(): array
    {
        if ($this->isAdministrator()) {
            return [self::ROLE_ADMIN, self::ROLE_USER];
        }
        if ($this->isAdmin()) {
            return [self::ROLE_USER];
        }
        return [];
    }

    /**
     * The set of users this person is allowed to see in the user-mgmt list.
     */
    public function visibleUsersQuery()
    {
        $q = static::query();
        if ($this->isAdministrator()) {
            return $q;
        }
        if ($this->isAdmin()) {
            return $q->where('created_by', $this->id);
        }
        return $q->whereRaw('1=0');     // user role sees nothing
    }

    /**
     * The set of mt5_accounts this user is allowed to see, by role:
     *   administrator → all accounts
     *   admin         → only accounts they created
     *   user          → only accounts explicitly assigned to them
     */
    public function visibleAccountsQuery()
    {
        $q = Mt5Account::query();
        if ($this->isAdministrator()) {
            return $q;
        }
        if ($this->isAdmin()) {
            return $q->where('created_by', $this->id);
        }
        // user role
        return $q->whereIn('id', $this->assignedAccounts()->pluck('mt5_accounts.id'));
    }
}

<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * `role` and `is_available` are deliberately NOT mass assignable: a registration or
     * profile form can never be tricked into promoting an account to admin.
     */
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    /** Defaults so a freshly `new`-ed / registered user is a usable customer. */
    protected $attributes = [
        'role' => 'customer',
        'is_available' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_available' => 'boolean',
            'last_assigned_at' => 'datetime',
        ];
    }

    // ---- Roles -------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isAgent(): bool
    {
        return $this->role === UserRole::Agent;
    }

    public function isCustomer(): bool
    {
        return $this->role === UserRole::Customer;
    }

    /** Admin or agent - anyone allowed to see internal notes / the audit trail. */
    public function isStaff(): bool
    {
        return $this->isAdmin() || $this->isAgent();
    }

    // ---- Scopes ------------------------------------------------------------

    public function scopeAgents(Builder $query): Builder
    {
        return $query->where('users.role', UserRole::Agent->value);
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('users.role', UserRole::Admin->value);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('users.is_available', true);
    }

    // ---- Relations ---------------------------------------------------------

    /** Categories an agent is skilled in. */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'agent_category');
    }

    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_agent_id');
    }

    public function createdTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }
}

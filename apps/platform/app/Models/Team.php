<?php

namespace App\Models;

use App\Enums\TeamPermission;
use App\Enums\TeamRole;
use App\Support\Audit\ActorContextManager;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'owner_id'])]
class Team extends Model
{
    use HasFactory, HasUuids;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function mobileApps(): HasMany
    {
        return $this->hasMany(MobileApp::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function buildRecords(): HasMany
    {
        return $this->hasMany(BuildRecord::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function ciCdPipelines(): HasMany
    {
        return $this->hasMany(CiCdPipeline::class);
    }

    public function ciCdRuns(): HasMany
    {
        return $this->hasMany(CiCdRun::class);
    }

    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    public function roleFor(User $user): ?TeamRole
    {
        if ($this->owner_id === $user->id) {
            return TeamRole::Owner;
        }

        $role = $this->users()
            ->whereKey($user->id)
            ->value('team_user.role');

        return is_string($role) ? TeamRole::tryFrom($role) : null;
    }

    public function allows(User $user, TeamPermission $permission): bool
    {
        return $this->roleFor($user)?->allows($permission) ?? false;
    }

    public function addUser(User $user, TeamRole|string $role): void
    {
        $value = $role instanceof TeamRole ? $role->value : $role;

        $this->users()->syncWithoutDetaching([
            $user->id => ['role' => $value],
        ]);
    }

    /**
     * Explicitly record a membership change as an audit row.
     *
     * belongsToMany pivot changes (attach/detach/sync on `users()`) are not captured
     * by the Auditable trait, which only observes the model's own attribute changes.
     * Call this at every membership add/remove/role-change call site.
     *
     * NOTE: there is no add/remove/role-change *management* flow in this app yet
     * (`addUser()` is only exercised by tests); this helper is forward-looking and is
     * currently wired into the two real membership-creation sites today:
     * `TeamController@store` and `CreateNewUser@create` (self-registration). Pivot
     * changes are not auto-audited, so this explicit call is required at every
     * membership add/remove/role-change site.
     */
    public function recordMembershipAudit(User $member, string $event, ?string $role): Audit
    {
        $context = app(ActorContextManager::class)->current();

        return Audit::create([
            'user_type' => $context->originator ? User::class : null,
            'user_id' => $context->originator?->id,
            'event' => $event,
            'auditable_type' => self::class,
            'auditable_id' => $this->id,
            'old_values' => [],
            'new_values' => ['member_id' => $member->id, 'role' => $role],
            'source' => $context->source,
            'team_id' => $this->id,
            'api_key_id' => $context->apiKey?->id,
        ]);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function booted(): void
    {
        static::creating(function (Team $team) {
            $baseSlug = Str::slug($team->slug ?: $team->name) ?: 'account';
            $slug = $baseSlug;
            $suffix = 2;

            while (static::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            $team->slug = $slug;
        });

        static::deleting(function (Team $team) {
            $team->mobileApps->each->delete();
            $team->credentials->each->delete();
        });
    }
}

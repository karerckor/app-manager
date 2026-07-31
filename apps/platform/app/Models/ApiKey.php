<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ApiKey extends PersonalAccessToken implements Auditable
{
    /** @use HasFactory<ApiKeyFactory> */
    use AuditableTrait {
        readyForAuditing as private traitReadyForAuditing;
    }

    use HasFactory;

    protected $table = 'personal_access_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'name',
        'token',
        'abilities',
        'expires_at',
        'tokenable_id',
        'tokenable_type',
    ];

    /**
     * The token hash is a one-way digest of the plaintext secret, but it must never
     * appear in audit history regardless — audits are queryable/exportable and are
     * not a substitute for the access controls guarding the token itself.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['token', 'last_used_at'];

    public function readyForAuditing(): bool
    {
        if (! $this->traitReadyForAuditing()) {
            return false;
        }

        if ($this->getAuditEvent() !== 'updated') {
            return true;
        }

        $changes = array_values(array_diff(
            array_keys($this->getChanges()),
            [$this->getUpdatedAtColumn()],
        ));

        return $changes !== ['last_used_at'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

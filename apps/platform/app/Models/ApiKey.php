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
    use AuditableTrait, HasFactory;

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
    protected array $auditExclude = ['token'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

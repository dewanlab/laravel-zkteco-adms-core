<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model mapping a user (PIN) to physical door access rights with timezone constraints.
 *
 * Used within the ZKTeco ADMS access control subsystem to configure which physical doors an
 * authorized user can access, and during which predefined device timezones (`timezone_id`).
 *
 * Enforces a composite unique database index on `(pin, door_id, timezone_id)` to prevent
 * duplicate authorization rules during ADMS protocol synchronization (`USERAUTHORIZE` table).
 *
 * Example:
 * ```php
 * $auth = ZkUserAuthorization::firstOrCreate([
 *     'pin' => '1001',
 *     'door_id' => 1,
 *     'timezone_id' => 1,
 * ]);
 * ```
 *
 * @property int $id
 * @property string $pin
 * @property int $door_id
 * @property int $timezone_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ZkUser|null $user
 */
class ZkUserAuthorization extends Model
{
    protected $guarded = [];

    protected $casts = [
        'door_id' => 'integer',
        'timezone_id' => 'integer',
    ];

    /**
     * Get the dynamically prefixed table name for the model.
     *
     * Resolves the table prefix from `zkteco-adms-core.table_prefix` config (defaults to `zkteco_user_authorizations`).
     */
    public function getTable(): string
    {
        return config('zkteco-adms-core.table_prefix', 'zkteco_').'user_authorizations';
    }

    /**
     * Get the user associated with this authorization rule via PIN.
     *
     * @return BelongsTo<ZkUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ZkUser::class, 'pin', 'pin');
    }
}

<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model representing a user or employee registered on ZKTeco biometric terminals.
 *
 * In the ZKTeco ADMS push protocol, the PIN (`pin`) serves as the immutable primary
 * identifier linking transactions across terminals, biometric templates, and authorization records.
 *
 * This model tracks user credentials, privilege levels (e.g. 0=standard user, 14=super admin),
 * and maintains relationships with multimodal biometric templates (`ZkBiodata`),
 * access control door authorizations (`ZkUserAuthorization`), and attendance punch events (`ZkAttendanceLog`).
 * The table name is dynamically prefixed via configuration.
 *
 * Example:
 * ```php
 * $user = ZkUser::with(['biodata', 'authorizations'])->where('pin', '1001')->first();
 * ```
 *
 * @property int $id
 * @property string $pin
 * @property string|null $name
 * @property string|null $card_number
 * @property string|null $password
 * @property int $privilege
 * @property int $group_id
 * @property int $timezone_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ZkBiodata> $biodata
 * @property-read Collection<int, ZkUserAuthorization> $authorizations
 * @property-read Collection<int, ZkAttendanceLog> $attendanceLogs
 */
class ZkUser extends Model
{
    protected $guarded = [];

    /**
     * Get the dynamically prefixed table name for the model.
     *
     * Resolves the table prefix from `zkteco-adms-core.table_prefix` config (defaults to `zkteco_users`).
     */
    public function getTable(): string
    {
        return config('zkteco-adms-core.table_prefix', 'zkteco_').'users';
    }

    /**
     * Get the multimodal biometric templates (fingerprint, face, palm) belonging to this user.
     *
     * @return HasMany<ZkBiodata, $this>
     */
    public function biodata(): HasMany
    {
        return $this->hasMany(ZkBiodata::class, 'pin', 'pin');
    }

    /**
     * Get the physical door access authorizations assigned to this user.
     *
     * @return HasMany<ZkUserAuthorization, $this>
     */
    public function authorizations(): HasMany
    {
        return $this->hasMany(ZkUserAuthorization::class, 'pin', 'pin');
    }

    /**
     * Get the attendance punch records registered under this user's PIN.
     *
     * @return HasMany<ZkAttendanceLog, $this>
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(ZkAttendanceLog::class, 'pin', 'pin');
    }
}

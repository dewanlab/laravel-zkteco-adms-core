<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Models;

use DewanLab\LaravelZktecoAdmsCore\Enums\PunchState;
use DewanLab\LaravelZktecoAdmsCore\Enums\VerifyMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model representing a single punch or attendance event recorded by a ZKTeco terminal.
 *
 * Ingested from the terminal's HTTP POST `ATTLOG` push stream (`/iclock/cdata?table=ATTLOG`).
 * Each record has a composite unique database index on `(device_id, pin, recorded_at)`
 * to prevent duplicate ingestion during terminal reconnects, buffer flushes, and re-syncs.
 *
 * Tracks verification mode (fingerprint, facial recognition, palm, card, password), punch state
 * (check-in, check-out, overtime, break), terminal timestamp (`recorded_at`), server reception
 * timestamp (`occurred_at`), optional work code, and the original unparsed payload.
 *
 * Example:
 * ```php
 * $punches = ZkAttendanceLog::where('pin', '1001')
 *     ->whereDate('recorded_at', today())
 *     ->orderBy('recorded_at')
 *     ->get();
 * ```
 *
 * @property int $id
 * @property int|null $device_id
 * @property string $pin
 * @property Carbon $recorded_at
 * @property Carbon|null $occurred_at
 * @property PunchState $status
 * @property VerifyMode $verify_mode
 * @property string|null $work_code
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ZkDevice|null $device
 * @property-read ZkUser|null $user
 */
class ZkAttendanceLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'recorded_at' => 'datetime',
        'occurred_at' => 'datetime',
        'status' => PunchState::class,
        'verify_mode' => VerifyMode::class,
        'raw_payload' => 'array',
    ];

    /**
     * Get the dynamically prefixed table name for the model.
     *
     * Resolves the table prefix from `zkteco-adms-core.table_prefix` config (defaults to `zkteco_attendance_logs`).
     */
    public function getTable(): string
    {
        return config('zkteco-adms-core.table_prefix', 'zkteco_').'attendance_logs';
    }

    /**
     * Get the terminal device where this punch occurred.
     *
     * @return BelongsTo<ZkDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ZkDevice::class, 'device_id');
    }

    /**
     * Get the user associated with this punch record via PIN.
     *
     * @return BelongsTo<ZkUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ZkUser::class, 'pin', 'pin');
    }
}

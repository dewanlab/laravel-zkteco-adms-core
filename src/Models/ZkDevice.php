<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Models;

use DewanLab\LaravelZktecoAdmsCore\Enums\DeviceStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Eloquent model representing a registered ZKTeco biometric terminal.
 *
 * Stores device identity (serial number), network parameters (IP address),
 * firmware and push protocol versions, operational options (JSON payload returned
 * during initial handshake), and online/offline activity tracking.
 *
 * Terminals communicate with the server via the ZKTeco ADMS (Automatic Data Master Server)
 * push protocol over HTTP. The database table name is dynamically prefixed via configuration.
 *
 * Example:
 * ```php
 * $device = ZkDevice::where('serial_number', 'SN12345678')->first();
 * $device->markAsOnline(request()->ip());
 * ```
 *
 * @property int $id
 * @property string $serial_number
 * @property string|null $name
 * @property string|null $ip_address
 * @property DeviceStatus $status
 * @property Carbon|null $last_activity_at
 * @property string|null $device_type
 * @property string|null $push_version
 * @property string|null $firmware_version
 * @property string $timezone
 * @property array<string, mixed>|null $options
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ZkAttendanceLog> $attendanceLogs
 * @property-read Collection<int, ZkDeviceCommand> $commands
 */
class ZkDevice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => DeviceStatus::class,
        'last_activity_at' => 'datetime',
        'options' => 'array',
    ];

    /**
     * Get the dynamically prefixed table name for the model.
     *
     * Resolves the table prefix from `zkteco-adms-core.table_prefix` config (defaults to `zkteco_devices`).
     */
    public function getTable(): string
    {
        return config('zkteco-adms-core.table_prefix', 'zkteco_').'devices';
    }

    /**
     * Get the attendance punch logs recorded by this terminal.
     *
     * @return HasMany<ZkAttendanceLog, $this>
     */
    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(ZkAttendanceLog::class, 'device_id');
    }

    /**
     * Get the outbound commands queued for or dispatched to this terminal.
     *
     * @return HasMany<ZkDeviceCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(ZkDeviceCommand::class, 'device_id');
    }

    /**
     * Mark the device as online, record current timestamp, and update its IP address.
     *
     * Invoked whenever a terminal performs an HTTP handshake, heartbeat, or log push.
     *
     * @param  string|null  $ipAddress  Client IP address from incoming HTTP request.
     */
    public function markAsOnline(?string $ipAddress = null): void
    {
        $this->update([
            'status' => DeviceStatus::Online,
            'last_activity_at' => now(),
            'ip_address' => $ipAddress ?? $this->ip_address,
        ]);
    }
}

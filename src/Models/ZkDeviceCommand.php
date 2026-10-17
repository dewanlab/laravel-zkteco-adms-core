<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Models;

use DewanLab\LaravelZktecoAdmsCore\Enums\CommandStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent model representing an outbound command in the asynchronous ADMS device command queue.
 *
 * Because ZKTeco biometric terminals poll the server from behind NAT/firewalls, instructions cannot
 * be pushed via direct inbound connections. Instead, commands follow a strict three-stage lifecycle:
 * 1. **Pending**: The command is created on the server and queued in the database (`CommandStatus::Pending`).
 * 2. **Sent**: The command is delivered to the device during its periodic `/iclock/getrequest` HTTP GET poll,
 *    formatted in the `C:<ID>:<CMD>` wire protocol (`CommandStatus::Sent`).
 * 3. **Acknowledged / Failed**: The device executes the instruction and confirms the result via an HTTP POST
 *    to `/iclock/devicecmd` (`ID=<ID>&Return=<CODE>&CMD=<CMD>`). A return code of `0` transitions the command to
 *    `CommandStatus::Acknowledged`, while any non-zero return code transitions it to `CommandStatus::Failed`.
 *
 * Example:
 * ```php
 * $cmd = ZkDeviceCommand::create([
 *     'device_id' => $device->id,
 *     'command_id' => 101,
 *     'command_type' => 'CONTROL DEVICE',
 *     'command_string' => 'CONTROL DEVICE 010101FF05',
 *     'status' => CommandStatus::Pending,
 *     'queued_at' => now(),
 * ]);
 * ```
 *
 * @property int $id
 * @property int $device_id
 * @property int $command_id
 * @property string $command_type
 * @property string $command_string
 * @property CommandStatus $status
 * @property int|null $return_code
 * @property string|null $error_message
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ZkDevice $device
 */
class ZkDeviceCommand extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => CommandStatus::class,
        'command_id' => 'integer',
        'return_code' => 'integer',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /**
     * Get the dynamically prefixed table name for the model.
     *
     * Resolves the table prefix from `zkteco-adms-core.table_prefix` config (defaults to `zkteco_device_commands`).
     */
    public function getTable(): string
    {
        return config('zkteco-adms-core.table_prefix', 'zkteco_').'device_commands';
    }

    /**
     * Get the target biometric terminal for this command.
     *
     * @return BelongsTo<ZkDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ZkDevice::class, 'device_id');
    }

    /**
     * Transition the command status to Sent when delivered to the terminal via `/iclock/getrequest`.
     */
    public function markAsSent(): void
    {
        $this->update([
            'status' => CommandStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    /**
     * Transition the command status to Acknowledged upon receiving successful execution callback (`Return=0`).
     *
     * @param  int  $returnCode  Device return code (0 indicates successful execution).
     */
    public function markAsAcknowledged(int $returnCode = 0): void
    {
        $this->update([
            'status' => CommandStatus::Acknowledged,
            'return_code' => $returnCode,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * Transition the command status to Failed upon receiving non-zero return code or error response.
     *
     * @param  int  $returnCode  Non-zero error code returned by the device.
     * @param  string|null  $errorMessage  Human-readable description of the error code.
     */
    public function markAsFailed(int $returnCode, ?string $errorMessage = null): void
    {
        $this->update([
            'status' => CommandStatus::Failed,
            'return_code' => $returnCode,
            'error_message' => $errorMessage,
            'acknowledged_at' => now(),
        ]);
    }
}

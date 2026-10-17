<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Events;

use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDeviceCommand;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a device reports a command execution failure (Return != 0).
 *
 * Triggered when a terminal posts an acknowledgment callback to `/iclock/devicecmd`
 * containing a non-zero error return code.
 *
 * Common ADMS protocol return failure codes include:
 * - `-1`: Execution Failure (command not supported by device firmware or target table missing).
 * - `-3`: Upgrade Failure (firmware file corrupted or checksum mismatch).
 * - `-30`: Invalid Parameter (command arguments violate firmware schema constraints).
 * - `-1002`: Deprecated Command (e.g. attempting legacy `USER ADD` instead of `DATA UPDATE`).
 *
 * Application listeners can use this event to trigger operational alerts, log errors,
 * or initiate fallback synchronization workflows.
 *
 * Example:
 * ```php
 * Event::listen(CommandFailed::class, function (CommandFailed $event) {
 *     Log::error("Command #{$event->command->id} failed on {$event->device->serial_number} [Code {$event->returnCode}]: {$event->errorMessage}");
 * });
 * ```
 */
class CommandFailed
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  ZkDeviceCommand  $command  The command instance that failed execution.
     * @param  ZkDevice  $device  The terminal that reported the execution failure.
     * @param  int  $returnCode  The non-zero error code returned by the device.
     * @param  string|null  $errorMessage  Human-readable description of the failure code.
     */
    public function __construct(
        public ZkDeviceCommand $command,
        public ZkDevice $device,
        public int $returnCode,
        public ?string $errorMessage = null,
    ) {}
}

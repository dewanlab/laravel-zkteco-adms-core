<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Events;

use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDeviceCommand;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a device successfully executes a server command (Return=0).
 *
 * Triggered when a terminal posts an acknowledgment callback to `/iclock/devicecmd`
 * reporting successful command execution.
 *
 * Application listeners can use this event to confirm physical door unlock actuations,
 * verify completed user and biometric template provisioning, or track firmware updates.
 *
 * Example:
 * ```php
 * Event::listen(CommandAcknowledged::class, function (CommandAcknowledged $event) {
 *     Log::info("Command #{$event->command->id} ({$event->command->command_type}) succeeded on {$event->device->serial_number}");
 * });
 * ```
 */
class CommandAcknowledged
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  ZkDeviceCommand  $command  The command instance executed by the device.
     * @param  ZkDevice  $device  The terminal that executed and acknowledged the command.
     * @param  int  $returnCode  The execution return code from the terminal (0 indicates success).
     */
    public function __construct(
        public ZkDeviceCommand $command,
        public ZkDevice $device,
        public int $returnCode = 0,
    ) {}
}

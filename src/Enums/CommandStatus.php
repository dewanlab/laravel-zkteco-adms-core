<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Enums;

/**
 * Lifecycle states of a server-to-device command in the asynchronous ADMS command queue.
 *
 * In the ZKTeco ADMS Push Protocol, communication is edge-initiated. The server
 * cannot push commands directly to devices over unsolicited connections; instead,
 * commands flow through an asynchronous lifecycle:
 *
 * 1. `Pending`: The command is enqueued in the database awaiting dispatch.
 * 2. `Sent`: The terminal polls `GET /iclock/getrequest?SN=<serial>` and the server
 *    dispatches pending commands in plaintext (`C:<id>:<command>`).
 * 3. `Acknowledged` / `Failed`: After executing the command, the terminal sends a callback
 *    via `POST /iclock/devicecmd?SN=<serial>` containing the execution result (`Return=0`
 *    for success, negative code for failure).
 *
 * Example:
 * ```php
 * if ($command->status->isCompleted()) {
 *     // Command execution is finalized.
 * }
 * ```
 */
enum CommandStatus: string
{
    /**
     * The command is queued in the database waiting for the device to poll /iclock/getrequest.
     */
    case Pending = 'pending';

    /**
     * The command has been delivered to the device in response to /iclock/getrequest.
     */
    case Sent = 'sent';

    /**
     * The device confirmed successful execution via /iclock/devicecmd callback (Return=0).
     */
    case Acknowledged = 'acknowledged';

    /**
     * The device reported execution failure via /iclock/devicecmd callback or timed out.
     */
    case Failed = 'failed';

    /**
     * Determine whether the command has reached a terminal lifecycle state.
     *
     * @return bool True if the command is either Acknowledged or Failed.
     */
    public function isCompleted(): bool
    {
        return $this === self::Acknowledged || $this === self::Failed;
    }
}

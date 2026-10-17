<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\DTOs;

/**
 * Represents the asynchronous execution result returned by a ZKTeco device via POST /iclock/devicecmd.
 *
 * In the ZKTeco ADMS Push Protocol, edge devices execute queued commands received
 * from the `/iclock/getrequest` endpoint and asynchronously acknowledge completion
 * via `POST /iclock/devicecmd?SN=<serial>`.
 *
 * The callback body or query data is typically formatted as:
 * `ID=<CmdID>&Return=<Code>&CMD=<CommandName>`
 *
 * Known Return Codes:
 * - `0`: Success (Command completed successfully on device).
 * - `-1`: Execution Failure (Command not supported or table missing).
 * - `-3`: Upgrade Failure (Firmware corrupted or checksum mismatch).
 * - `-30`: Invalid Parameter (Schema constraint violated or invalid format).
 * - `-1002`: Deprecated Command Syntax (e.g. legacy `USER ADD` instead of `DATA UPDATE`).
 *
 * Example:
 * ```php
 * $ack = new CommandAck(commandId: 42, returnCode: 0, command: 'DATA UPDATE');
 * if ($ack->isSuccess()) {
 *     // Command acknowledged successfully
 * }
 * ```
 */
final readonly class CommandAck
{
    /**
     * Create a new CommandAck instance.
     *
     * @param  int  $commandId  Identifier of the server command being acknowledged.
     * @param  int  $returnCode  Execution result code returned by the device (0 for success).
     * @param  string  $command  Name or type of the command (e.g. DATA UPDATE, INFO).
     * @param  string  $raw  Raw unparsed response line or query string received from the device.
     */
    public function __construct(
        public int $commandId,
        public int $returnCode,
        public string $command = '',
        public string $raw = '',
    ) {}

    /**
     * Determine whether the command was executed successfully on the device.
     *
     * @return bool True if the device returned code 0 (success).
     */
    public function isSuccess(): bool
    {
        return $this->returnCode === 0;
    }

    /**
     * Determine whether the device rejected the command due to deprecated syntax (Return = -1002).
     *
     * Occurs when attempting to use legacy protocol syntax (e.g. `USER ADD` instead of `DATA UPDATE userinfo`).
     *
     * @return bool True if deprecated command syntax was indicated.
     */
    public function isDeprecatedCommand(): bool
    {
        return $this->returnCode === -1002;
    }

    /**
     * Determine whether the device rejected the command due to invalid parameters (Return = -30).
     *
     * Occurs when a parameter violates field constraints or exceeds supported limits.
     *
     * @return bool True if invalid parameter schema violation occurred.
     */
    public function isInvalidParameter(): bool
    {
        return $this->returnCode === -30;
    }

    /**
     * Get a human-readable diagnostic error message for the return code.
     *
     * @return string|null Diagnostic error message, or null if the command succeeded.
     */
    public function errorMessage(): ?string
    {
        return match ($this->returnCode) {
            0 => null,
            -1 => 'Execution Failure (Command not supported or table missing)',
            -3 => 'Upgrade Failure (Firmware corrupted or checksum mismatch)',
            -30 => 'Invalid Parameter (Schema constraint violated)',
            -1002 => 'Deprecated Command (e.g. USER ADD instead of DATA UPDATE)',
            -1003 => 'Device Busy / Enrollment Operation Not Supported on Current UI State',
            -1004 => 'Unsupported Table or Parameter Schema for Current Firmware Version',
            default => "Unknown Failure (Return code {$this->returnCode})",
        };
    }
}

<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\DTOs;

/**
 * Manages door access control authorization for a user on a ZKTeco terminal.
 *
 * **CRITICAL FIRMWARE NOTICE**:
 * Due to a known firmware bug in the ZKTeco `userauthorize` table implementation,
 * issuing a `DATA UPDATE userauthorize` command without previously deleting old
 * authorizations causes rights to stack rather than overwrite. When rights stack,
 * the terminal grants access under both old and new timezone definitions simultaneously,
 * leading to unintended access privilege escalation.
 *
 * To guarantee safe permission synchronization, this DTO provides a two-step
 * purge-then-update command sequence:
 * 1. {@see toPurgeCommand()}: `DATA DELETE userauthorize Pin=<pin>`
 * 2. {@see toUpdateCommand()}: `DATA UPDATE userauthorize Pin=<pin> AuthorizeTimezoneId=<tz> AuthorizeDoorId=<door>`
 *
 * Example:
 * ```php
 * $auth = new AccessAuthorization(pin: '1001', doorId: 1, timezoneId: 1);
 * $queue->enqueue($device, $auth->toPurgeCommand());
 * $queue->enqueue($device, $auth->toUpdateCommand());
 * ```
 */
final readonly class AccessAuthorization
{
    /**
     * Create a new AccessAuthorization instance.
     *
     * @param  string  $pin  User employee identifier (PIN) being authorized.
     * @param  int  $doorId  Target door index or relay number on the terminal.
     * @param  int  $timezoneId  Access control timezone table index (1-255).
     */
    public function __construct(
        public string $pin,
        public int $doorId,
        public int $timezoneId,
    ) {}

    /**
     * Generate the purge command to avoid the firmware authorization stacking bug.
     *
     * Produces `DATA DELETE userauthorize Pin=<pin>`.
     *
     * @return string ADMS command string to delete existing authorization records for this PIN.
     */
    public function toPurgeCommand(): string
    {
        return sprintf('DATA DELETE userauthorize Pin=%s', $this->pin);
    }

    /**
     * Generate the update command to assign door and timezone authorization.
     *
     * Produces `DATA UPDATE userauthorize Pin=<pin> AuthorizeTimezoneId=<tz> AuthorizeDoorId=<door>`.
     *
     * @return string ADMS command string to provision the user's access rights.
     */
    public function toUpdateCommand(): string
    {
        return sprintf(
            'DATA UPDATE userauthorize Pin=%s AuthorizeTimezoneId=%d AuthorizeDoorId=%d',
            $this->pin,
            $this->timezoneId,
            $this->doorId
        );
    }
}

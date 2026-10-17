<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Services\Commands;

use DewanLab\LaravelZktecoAdmsCore\DTOs\AccessAuthorization;
use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use InvalidArgumentException;

/**
 * Fluent builder for constructing ZKTeco ADMS wire-format command strings.
 *
 * In the ZKTeco ADMS Push Protocol, the server transmits management and configuration
 * instructions to hardware terminals through plain-text command strings. All commands follow
 * the protocol's strict plain-text syntax and are fetched by terminals during their periodic
 * polling cycle (/iclock/getrequest).
 *
 * Key commands supported:
 * - Maintenance: `REBOOT`, `CHECK`, `CLEAR LOG`, `CLEAR DATA`
 * - Data Operations: `DATA UPDATE user`, `DATA DELETE user`, `DATA UPDATE biodata`, `DATA DELETE biodata`
 * - Access Control: `DATA DELETE userauthorize`, `DATA UPDATE userauthorize`
 * - Hardware Relays: `CONTROL DEVICE` (10-byte hex actuation for doors and alarms)
 * - Remote Enrollment: `ENROLL_BIO` (prompts physical UI for biometric capture)
 *
 * Firmware quirk handling:
 * IMPORTANT: {@see DeviceCommandBuilder::authorizeAccess()} emits a 2-stage command sequence
 * (`DATA DELETE userauthorize` before `DATA UPDATE userauthorize`) to work around a known
 * ZKTeco firmware authorization stacking bug where repeated updates without a purge
 * corrupt device access permissions.
 *
 * Example:
 * ```php
 * $builder = new DeviceCommandBuilder();
 * $rebootCmd = $builder->reboot();
 * $unlockCmd = $builder->unlockDoor(doorId: 1, durationSeconds: 5);
 * ```
 */
class DeviceCommandBuilder
{
    /**
     * Build a command to reboot the device.
     *
     * Wire command: `REBOOT`
     *
     * @return string Plain-text REBOOT command.
     */
    public function reboot(): string
    {
        return 'REBOOT';
    }

    /**
     * Build a command instructing the device to verify its time and check for pending tasks.
     *
     * Wire command: `CHECK`
     *
     * @return string Plain-text CHECK command.
     */
    public function checkTime(): string
    {
        return 'CHECK';
    }

    /**
     * Build a command to clear all transaction and attendance logs stored on the terminal.
     *
     * Wire command: `CLEAR LOG`
     *
     * @return string Plain-text CLEAR LOG command.
     */
    public function clearLogs(): string
    {
        return 'CLEAR LOG';
    }

    /**
     * Build a command to factory-reset and clear all user credentials and templates from the device.
     *
     * Wire command: `CLEAR DATA`
     *
     * @return string Plain-text CLEAR DATA command.
     */
    public function clearData(): string
    {
        return 'CLEAR DATA';
    }

    /**
     * Build a command to create or update a user on the terminal.
     *
     * Wire command: `DATA UPDATE user Pin=...\tName=...\tPrivilege=...\tCard=...\tPasswd=...\tGrp=...\tTZ=...`
     *
     * @param  string  $pin  User PIN / ID on the terminal.
     * @param  string  $name  Display name of the user.
     * @param  int  $privilege  Privilege level (0 = Normal User, 14 = Super Administrator).
     * @param  string  $card  RFID card number.
     * @param  string  $password  Keypad PIN password.
     * @param  int  $groupId  Access control group ID (default: 1).
     * @param  int  $timezone  Access control timezone index (default: 0).
     * @return string Formatted wire-protocol command string.
     *
     * @throws InvalidArgumentException If $pin or $name contains newline characters.
     */
    public function updateUser(
        string $pin,
        string $name,
        int $privilege = 0,
        string $card = '',
        string $password = '',
        int $groupId = 1,
        int $timezone = 0,
    ): string {
        $this->assertSafeString($pin, 'pin');
        $this->assertSafeString($name, 'name');

        return sprintf(
            "DATA UPDATE USERINFO PIN=%s\tName=%s\tPri=%d\tCard=%s\tPasswd=%s\tGrp=%d\tTZ=%d",
            $pin,
            $name,
            $privilege,
            $card,
            $password,
            $groupId,
            $timezone
        );
    }

    /**
     * Build a command to delete a user and their credentials from the terminal.
     *
     * Wire command: `DATA DELETE USERINFO PIN=...`
     *
     * @param  string  $pin  User PIN / ID on the terminal.
     * @return string Formatted wire-protocol command string.
     *
     * @throws InvalidArgumentException If $pin contains newline characters.
     */
    public function deleteUser(string $pin): string
    {
        $this->assertSafeString($pin, 'pin');

        return sprintf('DATA DELETE USERINFO PIN=%s', $pin);
    }

    /**
     * Build a command to query and pull users from the terminal.
     *
     * Wire command: `DATA QUERY USERINFO`
     *
     * @param  string  $table  Table name to query (default: 'USERINFO').
     * @return string Formatted wire-protocol command string.
     */
    public function queryUsers(string $table = 'USERINFO'): string
    {
        return sprintf('DATA QUERY %s', $table);
    }

    /**
     * Build a command to query and pull attendance punch records from the terminal.
     *
     * Wire command: `DATA QUERY ATTLOG [StartTime=... EndTime=...]`
     *
     * @param  string|null  $startTime  Optional filter start timestamp (Y-m-d H:i:s).
     * @param  string|null  $endTime  Optional filter end timestamp (Y-m-d H:i:s).
     * @return string Formatted wire-protocol command string.
     */
    public function queryAttendanceLogs(?string $startTime = null, ?string $endTime = null): string
    {
        $cmd = 'DATA QUERY ATTLOG';

        if ($startTime !== null && $endTime !== null) {
            $cmd .= sprintf(' StartTime=%s EndTime=%s', $startTime, $endTime);
        }

        return $cmd;
    }

    /**
     * Build a command to insert or update an attendance log record on the terminal.
     *
     * Wire command: `DATA UPDATE ATTLOG PIN=...\tTime=...\tStatus=...\tVerify=...`
     *
     * @param  string  $pin  User PIN / ID associated with the punch.
     * @param  string  $timestamp  Formatted punch timestamp (Y-m-d H:i:s).
     * @param  int  $status  Punch state code (0=CheckIn, 1=CheckOut, etc.).
     * @param  int  $verifyMode  Verification modality code (1=Fingerprint, 15=Face, etc.).
     * @return string Formatted wire-protocol command string.
     *
     * @throws InvalidArgumentException If $pin or $timestamp contains newline characters.
     */
    public function updateAttendanceLog(
        string $pin,
        string $timestamp,
        int $status = 0,
        int $verifyMode = 1,
    ): string {
        $this->assertSafeString($pin, 'pin');
        $this->assertSafeString($timestamp, 'timestamp');

        return sprintf(
            "DATA UPDATE ATTLOG PIN=%s\tTime=%s\tStatus=%d\tVerify=%d",
            $pin,
            $timestamp,
            $status,
            $verifyMode
        );
    }

    /**
     * Build a command to update or upload biometric template data to the terminal.
     *
     * Wire command: `DATA UPDATE biodata ...`
     *
     * @param  BiodataPayload  $biodata  Biometric template data transfer object.
     * @return string Formatted wire-protocol command string.
     */
    public function updateBiodata(BiodataPayload $biodata): string
    {
        return sprintf('DATA UPDATE biodata %s', $biodata->toCommandParams());
    }

    /**
     * Build a command to delete biometric templates for a user from the terminal.
     *
     * If $bioType is omitted, all biometric templates for the user are deleted.
     * If specified, only templates matching the given modality are removed.
     *
     * Wire command: `DATA DELETE biodata Pin=...[\tType=...]`
     *
     * @param  string  $pin  User PIN / ID on the terminal.
     * @param  BioType|null  $bioType  Optional biometric modality code to target.
     * @return string Formatted wire-protocol command string.
     *
     * @throws InvalidArgumentException If $pin contains newline characters.
     */
    public function deleteBiodata(string $pin, ?BioType $bioType = null): string
    {
        $this->assertSafeString($pin, 'pin');

        if ($bioType === null) {
            return sprintf('DATA DELETE biodata Pin=%s', $pin);
        }

        return sprintf("DATA DELETE biodata Pin=%s\tType=%d", $pin, $bioType->value);
    }

    /**
     * Authorize user access to a door and timezone.
     *
     * IMPORTANT: Works around the ZKTeco firmware authorization stacking bug by returning
     * an array of two sequential commands:
     * 1. `DATA DELETE userauthorize Pin=...` (clears existing authorizations)
     * 2. `DATA UPDATE userauthorize Pin=...` (applies the new authorization)
     *
     * @param  string  $pin  User PIN / ID on the terminal.
     * @param  int  $doorId  Target door/relay index (default: 1).
     * @param  int  $timezoneId  Allowed timezone slot index (default: 1).
     * @return array<int, string> Two-element command array [DELETE, UPDATE].
     *
     * @throws InvalidArgumentException If $pin contains newline characters.
     */
    public function authorizeAccess(string $pin, int $doorId = 1, int $timezoneId = 1): array
    {
        $this->assertSafeString($pin, 'pin');

        $auth = new AccessAuthorization($pin, $doorId, $timezoneId);

        return [
            $auth->toPurgeCommand(),
            $auth->toUpdateCommand(),
        ];
    }

    /**
     * Actuate terminal hardware relay (e.g. unlocking a physical door).
     *
     * Uses universal 10-byte hex format: `CONTROL DEVICE AA BB CC DD EE`
     * - AA: `01` = Relay, `02` = Alarm, `03` = Restart
     * - BB: Target Door/Relay ID (`00` = All, `01`-`10`)
     * - CC: Component (`01` = Lock Relay, `02` = Aux)
     * - DD: State (`00` = Normal/Off, `FF` = Trigger/Open)
     * - EE: Duration in hex seconds (`01` to `FF`)
     *
     * Wire command: `CONTROL DEVICE 01<doorHex>01FF<durationHex>`
     *
     * @param  int  $doorId  Door relay index (default: 1).
     * @param  int  $durationSeconds  Relay open duration in seconds (1 to 255, default: 5).
     * @return string Formatted wire-protocol CONTROL DEVICE command string.
     */
    public function unlockDoor(int $doorId = 1, int $durationSeconds = 5): string
    {
        $durationHex = str_pad(dechex(max(1, min(255, $durationSeconds))), 2, '0', STR_PAD_LEFT);
        $doorHex = str_pad(dechex($doorId), 2, '0', STR_PAD_LEFT);

        return sprintf('CONTROL DEVICE 01%s01%s%s', $doorHex, 'FF', $durationHex);
    }

    /**
     * Build a command to cancel an active hardware alarm or siren on the terminal.
     *
     * Wire command: `CONTROL DEVICE 0200000000`
     *
     * @return string Formatted wire-protocol command string.
     */
    public function cancelAlarm(): string
    {
        return 'CONTROL DEVICE 0200000000';
    }

    /**
     * Build a command to trigger remote biometric enrollment on the terminal UI.
     *
     * Instructs the hardware terminal to display the enrollment prompt and capture
     * the specified biometric template directly from the user.
     *
     * Wire command: `ENROLL_BIO TYPE=<Type> NO=0 PIN=<Pin> RETRY=<Retry> OVERWRITE=1 MODE=<Mode>`
     *
     * @param  string  $pin  User PIN / ID to enroll biometrics for.
     * @param  BioType  $type  Biometric modality to capture (default: VisibleLightFace).
     * @param  int  $retry  Number of capture attempts allowed before timeout (default: 3).
     * @param  int  $mode  Enrollment mode flag (1 = new/overwrite, default: 1).
     * @return string Formatted wire-protocol ENROLL_BIO command string.
     *
     * @throws InvalidArgumentException If $pin contains newline characters.
     */
    public function enrollBio(string $pin, BioType $type = BioType::VisibleLightFace, int $retry = 3, int $mode = 1): string
    {
        $this->assertSafeString($pin, 'pin');

        return sprintf(
            'ENROLL_BIO TYPE=%d NO=0 PIN=%s RETRY=%d OVERWRITE=1 MODE=%d',
            $type->value,
            $pin,
            $retry,
            $mode
        );
    }

    /**
     * Return a sanitized raw ADMS command string.
     *
     * @param  string  $command  Raw ADMS command string.
     * @return string Trimmed command string.
     */
    public function raw(string $command): string
    {
        return trim($command);
    }

    /**
     * Assert that a parameter value contains no newline characters to prevent command injection.
     *
     * @param  string  $val  Parameter value to check.
     * @param  string  $field  Field name for exception identification.
     *
     * @throws InvalidArgumentException If $val contains carriage return or newline characters.
     */
    private function assertSafeString(string $val, string $field): void
    {
        if (str_contains($val, "\r") || str_contains($val, "\n")) {
            throw new InvalidArgumentException("Forbidden newline characters in {$field}");
        }
    }
}

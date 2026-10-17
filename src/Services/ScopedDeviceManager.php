<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Services;

use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDeviceCommand;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUser;
use DewanLab\LaravelZktecoAdmsCore\ZktecoAdmsCore;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fluent device-scoped manager for ZKTeco ADMS terminals.
 *
 * Encapsulates a target device context so developers can invoke employee management,
 * attendance querying, and device control actions without repeatedly passing the device identifier.
 *
 * Example:
 * ```php
 * $device = ZktecoAdms::device('C3-400-DEMO');
 *
 * // Employee CRUD
 * $device->createUser('1001', 'Alice', card: '123456');
 * $device->createUsers([['pin' => '1002', 'name' => 'Bob']]);
 * $device->updateUser('1001', ['name' => 'Alice Smith']);
 * $device->deleteUser('1001');
 *
 * // Attendance Queries
 * $logs = $device->getAttendanceLogs();
 * $today = $device->getTodayAttendanceLogs();
 *
 * // Device Controls
 * $device->unlockDoor(1, 5);
 * $device->reboot();
 * ```
 */
class ScopedDeviceManager
{
    /**
     * Create a new ScopedDeviceManager instance.
     *
     * @param  ZktecoAdmsCore  $core  The underlying core service.
     * @param  ZkDevice  $device  The resolved device model.
     */
    public function __construct(
        protected ZktecoAdmsCore $core,
        protected ZkDevice $device,
    ) {}

    /**
     * Get the bound device model instance.
     *
     * @return ZkDevice The device model.
     */
    public function getDevice(): ZkDevice
    {
        return $this->device;
    }

    /**
     * Create or update an employee in the database and push to this device.
     *
     * @param  string  $pin  Employee PIN / ID.
     * @param  string  $name  Employee display name.
     * @param  int  $privilege  Privilege level (0 = Normal User, 14 = Super Admin).
     * @param  string  $card  RFID card number.
     * @param  string  $password  Keypad PIN password.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function createUser(
        string $pin,
        string $name,
        int $privilege = 0,
        string $card = '',
        string $password = '',
    ): ZkDeviceCommand {
        return $this->core->createUser($this->device, $pin, $name, $privilege, $card, $password);
    }

    /**
     * Bulk create or update employees in the database and push them to this device.
     *
     * @param  array<int, array<string, mixed>|ZkUser>|Collection<int, ZkUser>  $users  Array of user attribute arrays or ZkUser models.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function createUsers(array|Collection $users): array
    {
        return $this->core->createUsers($this->device, $users);
    }

    /**
     * Sync all existing users from the database to this device, or push a specific user collection.
     *
     * @param  Collection<int, ZkUser>|array<int, ZkUser>|null  $users  Users to push, or null for all.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function syncUsers(Collection|array|null $users = null): array
    {
        return $this->push($users);
    }

    /**
     * Push local database employees to this device.
     *
     * @param  Collection<int, ZkUser>|array<int, ZkUser>|null  $users  Users to push, or null for all.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function push(Collection|array|null $users = null): array
    {
        return $this->core->push($this->device, $users);
    }

    /**
     * Pull users from this device into the local database.
     *
     * @return ZkDeviceCommand The enqueued query command record.
     */
    public function pullUsers(): ZkDeviceCommand
    {
        return $this->core->pullUsers($this->device);
    }

    /**
     * Pull historical attendance logs from this device into the local database.
     *
     * @param  string|null  $startTime  Optional filter start timestamp.
     * @param  string|null  $endTime  Optional filter end timestamp.
     * @return ZkDeviceCommand The enqueued query command record.
     */
    public function pullLogs(?string $startTime = null, ?string $endTime = null): ZkDeviceCommand
    {
        return $this->core->pullLogs($this->device, $startTime, $endTime);
    }

    /**
     * Pull both users and old attendance logs from this device into the database.
     *
     * @return array<int, ZkDeviceCommand> Array containing user and log query commands.
     */
    public function pull(): array
    {
        return $this->core->pull($this->device);
    }

    /**
     * Perform bidirectional synchronization with this device.
     *
     * Pulls users and old logs from the device and pushes local database users.
     *
     * @param  Collection<int, ZkUser>|array<int, ZkUser>|null  $users  Optional specific users to push.
     * @return array{pull: array<int, ZkDeviceCommand>, push: array<int, ZkDeviceCommand>}
     */
    public function sync(Collection|array|null $users = null): array
    {
        return $this->core->sync($this->device, $users);
    }

    /**
     * Update an employee's details locally and push to this device.
     *
     * @param  string  $pin  Employee PIN.
     * @param  array<string, mixed>  $attributes  Fields to update.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function updateUser(string $pin, array $attributes): ZkDeviceCommand
    {
        return $this->core->updateUser($this->device, $pin, $attributes);
    }

    /**
     * Delete an employee from this device and optionally from the database.
     *
     * @param  string  $pin  Employee PIN / ID.
     * @param  bool  $deleteFromDatabase  Whether to also delete the local database record.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function deleteUser(string $pin, bool $deleteFromDatabase = true): ZkDeviceCommand
    {
        return $this->core->deleteUser($this->device, $pin, $deleteFromDatabase);
    }

    /**
     * Bulk delete employees from this device and optionally from the database.
     *
     * @param  array<int, string>  $pins  Array of employee PINs to delete.
     * @param  bool  $deleteFromDatabase  Whether to also delete local database records.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function deleteUsers(array $pins, bool $deleteFromDatabase = true): array
    {
        return $this->core->deleteUsers($this->device, $pins, $deleteFromDatabase);
    }

    /**
     * Query attendance punch logs captured by this device.
     *
     * @return Collection<int, ZkAttendanceLog> Collection of matching attendance logs.
     */
    public function getAttendanceLogs(): Collection
    {
        return $this->core->getAttendanceLogs($this->device);
    }

    /**
     * Query attendance punch logs for a specific employee on this device.
     *
     * @param  string  $pin  Employee PIN.
     * @param  string|null  $fromDate  Optional start date / datetime filter.
     * @param  string|null  $toDate  Optional end date / datetime filter.
     * @return Collection<int, ZkAttendanceLog> Collection of matching attendance logs.
     */
    public function getAttendanceLogsForUser(
        string $pin,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Collection {
        $query = ZkAttendanceLog::with('user')
            ->where('device_id', $this->device->id)
            ->where('pin', $pin)
            ->latest('recorded_at');

        if ($fromDate !== null) {
            $query->where('recorded_at', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $query->where('recorded_at', '<=', $toDate);
        }

        return $query->get();
    }

    /**
     * Query attendance punch logs on this device within a specific date range.
     *
     * @param  string  $startDate  Start date (Y-m-d or datetime).
     * @param  string|null  $endDate  Optional end date (defaults to end of startDate).
     * @return Collection<int, ZkAttendanceLog> Collection of matching attendance logs.
     */
    public function getAttendanceLogsByDate(string $startDate, ?string $endDate = null): Collection
    {
        return $this->core->getAttendanceLogsByDate($startDate, $endDate, $this->device);
    }

    /**
     * Query today's attendance punch logs recorded by this device.
     *
     * @return Collection<int, ZkAttendanceLog> Collection of matching attendance logs for today.
     */
    public function getTodayAttendanceLogs(): Collection
    {
        return $this->core->getTodayAttendanceLogs($this->device);
    }

    /**
     * Queue a reboot command for this device.
     *
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function reboot(): ZkDeviceCommand
    {
        return $this->core->rebootDevice($this->device);
    }

    /**
     * Queue a relay actuation command to momentarily unlock a door.
     *
     * @param  int  $doorId  Target door/relay index (default: 1).
     * @param  int  $durationSeconds  Relay open duration in seconds (default: 5).
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function unlockDoor(int $doorId = 1, int $durationSeconds = 5): ZkDeviceCommand
    {
        return $this->core->unlockDoor($this->device, $doorId, $durationSeconds);
    }

    /**
     * Queue a command to clear/delete all attendance logs stored on this terminal.
     *
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function clearAttendanceLogs(): ZkDeviceCommand
    {
        return $this->core->clearAttendanceLogs($this->device);
    }

    /**
     * Authorize user access to a door and timezone schedule on this device.
     *
     * @param  string  $pin  User PIN / ID on the terminal.
     * @param  int  $doorId  Target door/relay index (default: 1).
     * @param  int  $timezoneId  Allowed timezone slot index (default: 1).
     * @return array<int, ZkDeviceCommand> Array containing purge and update command records.
     */
    public function authorizeUserAccess(string $pin, int $doorId = 1, int $timezoneId = 1): array
    {
        return $this->core->authorizeUserAccess($this->device, $pin, $doorId, $timezoneId);
    }

    /**
     * Upload biometric template data to this device.
     *
     * @param  BiodataPayload  $biodata  Biometric template data transfer object.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function syncBiodata(BiodataPayload $biodata): ZkDeviceCommand
    {
        return $this->core->syncBiodata($this->device, $biodata);
    }

    /**
     * Trigger remote biometric enrollment directly on this device screen.
     *
     * @param  string  $pin  User PIN / ID to enroll.
     * @param  BioType  $type  Biometric modality to capture.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function triggerRemoteEnrollment(
        string $pin,
        BioType $type = BioType::VisibleLightFace,
    ): ZkDeviceCommand {
        return $this->core->triggerRemoteEnrollment($this->device, $pin, $type);
    }
}

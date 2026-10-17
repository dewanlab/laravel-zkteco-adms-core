<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore;

use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDeviceCommand;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUser;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\CommandQueueManager;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\DeviceCommandBuilder;
use DewanLab\LaravelZktecoAdmsCore\Services\ScopedDeviceManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The main service class and public API for the Laravel ZKTeco ADMS package.
 *
 * Provides high-level, fluent methods for performing common device management
 * and access control operations:
 * - Device maintenance: Rebooting terminals.
 * - Access control: Actuating door relays and managing user access schedules.
 * - User management: Provisioning user profiles and credentials.
 * - Biometrics: Uploading biometric templates and initiating remote enrollment.
 *
 * All operations accept either a {@see ZkDevice} Eloquent model instance or a device
 * serial number string. Commands are constructed via {@see DeviceCommandBuilder}
 * and queued asynchronously via {@see CommandQueueManager}.
 *
 * Example:
 * ```php
 * $adms = app(ZktecoAdmsCore::class);
 *
 * // Reboot a terminal
 * $adms->rebootDevice('SN123456');
 *
 * // Momentarily unlock door #1 for 5 seconds
 * $adms->unlockDoor('SN123456', doorId: 1, durationSeconds: 5);
 *
 * // Provision user and access permissions
 * $adms->syncUser('SN123456', pin: '1001', name: 'Alice');
 * $adms->authorizeUserAccess('SN123456', pin: '1001', doorId: 1, timezoneId: 1);
 * ```
 */
class ZktecoAdmsCore
{
    /**
     * Create a new ZktecoAdmsCore service instance.
     *
     * @param  DeviceCommandBuilder  $commandBuilder  Wire-protocol command string generator.
     * @param  CommandQueueManager  $queueManager  Asynchronous database command queue manager.
     */
    public function __construct(
        protected DeviceCommandBuilder $commandBuilder,
        protected CommandQueueManager $queueManager,
    ) {}

    /**
     * Get the underlying device command builder instance.
     *
     * @return DeviceCommandBuilder The command builder.
     */
    public function builder(): DeviceCommandBuilder
    {
        return $this->commandBuilder;
    }

    /**
     * Get the underlying command queue manager instance.
     *
     * @return CommandQueueManager The command queue manager.
     */
    public function queue(): CommandQueueManager
    {
        return $this->queueManager;
    }

    /**
     * Scope operations to a specific target device for fluent method chaining.
     *
     * Allows invoking employee management, attendance queries, and hardware controls
     * without repeatedly passing the device model or serial number on every call.
     *
     * Example:
     * ```php
     * $device = ZktecoAdms::device('C3-400-DEMO');
     * $device->createUser('1001', 'Alice Smith');
     * $device->getAttendanceLogs();
     * $device->reboot();
     * ```
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @return ScopedDeviceManager Device-scoped manager instance.
     */
    public function device(ZkDevice|string $device): ScopedDeviceManager
    {
        return new ScopedDeviceManager($this, $this->resolveDevice($device));
    }

    /**
     * Queue a reboot command for a target device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function rebootDevice(ZkDevice|string $device): ZkDeviceCommand
    {
        $zkDevice = $this->resolveDevice($device);

        return $this->queueManager->enqueue($zkDevice, $this->commandBuilder->reboot(), 'REBOOT');
    }

    /**
     * Queue a relay actuation command to momentarily unlock a door.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  int  $doorId  Target door/relay index (default: 1).
     * @param  int  $durationSeconds  Relay open duration in seconds (default: 5).
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function unlockDoor(ZkDevice|string $device, int $doorId = 1, int $durationSeconds = 5): ZkDeviceCommand
    {
        $zkDevice = $this->resolveDevice($device);

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->unlockDoor($doorId, $durationSeconds),
            'RELAY'
        );
    }

    /**
     * Create or update an employee in the database and queue synchronization to device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string  $pin  Employee PIN / ID.
     * @param  string  $name  Employee display name.
     * @param  int  $privilege  Privilege level (0 = Normal User, 14 = Super Admin).
     * @param  string  $card  RFID card number.
     * @param  string  $password  Keypad PIN password.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function createUser(
        ZkDevice|string $device,
        string $pin,
        string $name,
        int $privilege = 0,
        string $card = '',
        string $password = '',
    ): ZkDeviceCommand {
        $zkDevice = $this->resolveDevice($device);

        ZkUser::updateOrCreate(
            ['pin' => $pin],
            [
                'name' => $name,
                'card_number' => $card !== '' ? $card : null,
                'password' => $password !== '' ? $password : null,
                'privilege' => $privilege,
            ]
        );

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->updateUser($pin, $name, $privilege, $card, $password),
            'DATA'
        );
    }

    /**
     * Bulk create or update employees in the database and push them to the device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  array<int, array<string, mixed>|ZkUser>|Collection<int, ZkUser>  $users  Array of user attribute arrays or ZkUser models.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function createUsers(
        ZkDevice|string $device,
        array|Collection $users,
    ): array {
        $zkDevice = $this->resolveDevice($device);
        $commands = [];

        foreach ($users as $user) {
            if ($user instanceof ZkUser) {
                $pin = (string) $user->pin;
                $name = (string) ($user->name ?? "User {$user->pin}");
                $privilege = (int) $user->privilege;
                $card = (string) ($user->card_number ?? '');
                $password = (string) ($user->password ?? '');
            } else {
                $pin = (string) ($user['pin'] ?? '');
                $name = (string) ($user['name'] ?? "User {$pin}");
                $privilege = (int) ($user['privilege'] ?? 0);
                $card = (string) ($user['card'] ?? $user['card_number'] ?? '');
                $password = (string) ($user['password'] ?? '');

                ZkUser::updateOrCreate(
                    ['pin' => $pin],
                    [
                        'name' => $name,
                        'card_number' => $card !== '' ? $card : null,
                        'password' => $password !== '' ? $password : null,
                        'privilege' => $privilege,
                    ]
                );
            }

            $commands[] = $this->commandBuilder->updateUser(
                pin: $pin,
                name: $name,
                privilege: $privilege,
                card: $card,
                password: $password
            );
        }

        return $this->queueManager->enqueueBatch($zkDevice, $commands, 'DATA');
    }

    /**
     * Alias for {@see createUser()} providing backward compatibility.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string  $pin  User PIN / ID on the terminal.
     * @param  string  $name  Display name of the user.
     * @param  int  $privilege  Privilege level (0 = Normal User, 14 = Super Administrator).
     * @param  string  $card  RFID card number.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function syncUser(
        ZkDevice|string $device,
        string $pin,
        string $name,
        int $privilege = 0,
        string $card = '',
    ): ZkDeviceCommand {
        return $this->createUser($device, $pin, $name, $privilege, $card);
    }

    /**
     * Alias for {@see createUsers()} providing backward compatibility.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  Collection<int, ZkUser>|array<int, ZkUser>|null  $users  Users to push, or null for all.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function syncUsers(
        ZkDevice|string $device,
        Collection|array|null $users = null,
    ): array {
        return $this->push($device, $users);
    }

    /**
     * Push local database employees to the terminal.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  Collection<int, ZkUser>|array<int, ZkUser>|null  $users  Users to push, or null for all users.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function push(
        ZkDevice|string $device,
        Collection|array|null $users = null,
    ): array {
        /** @var Collection<int, ZkUser> $userList */
        $userList = $users instanceof Collection ? $users : (is_array($users) ? new Collection($users) : ZkUser::all());

        return $this->createUsers($device, $userList);
    }

    /**
     * Pull users from the terminal into the local database.
     *
     * Wire command queued: `DATA QUERY tablename=USERINFO,fielddesc=*`
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @return ZkDeviceCommand The enqueued query command record.
     */
    public function pullUsers(ZkDevice|string $device): ZkDeviceCommand
    {
        $zkDevice = $this->resolveDevice($device);

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->queryUsers('USERINFO'),
            'DATA'
        );
    }

    /**
     * Pull historical attendance logs from the terminal into the local database.
     *
     * Flags the device to reset its Stamp watermark cursor to 0 on next handshake,
     * prompting the terminal to re-transmit its entire attendance archive, and queues
     * an on-demand ATTLOG query.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string|null  $startTime  Optional filter start timestamp.
     * @param  string|null  $endTime  Optional filter end timestamp.
     * @return ZkDeviceCommand The enqueued query command record.
     */
    public function pullLogs(
        ZkDevice|string $device,
        ?string $startTime = null,
        ?string $endTime = null,
    ): ZkDeviceCommand {
        $zkDevice = $this->resolveDevice($device);

        $options = $zkDevice->options ?? [];
        $options['force_pull_logs'] = true;
        $zkDevice->update(['options' => $options]);

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->queryAttendanceLogs($startTime, $endTime),
            'DATA'
        );
    }

    /**
     * Pull both users and historical attendance logs from the terminal into the local database.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @return array<int, ZkDeviceCommand> Array containing user and log query commands.
     */
    public function pull(ZkDevice|string $device): array
    {
        $userCmd = $this->pullUsers($device);
        $logsCmd = $this->pullLogs($device);

        return [$userCmd, $logsCmd];
    }

    /**
     * Perform bidirectional synchronization with the terminal.
     *
     * Pulls users and old logs from the terminal and pushes local database users to the device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  Collection<int, ZkUser>|array<int, ZkUser>|null  $users  Optional specific users to push.
     * @return array{pull: array<int, ZkDeviceCommand>, push: array<int, ZkDeviceCommand>}
     */
    public function sync(ZkDevice|string $device, Collection|array|null $users = null): array
    {
        $pullCommands = $this->pull($device);
        $pushCommands = $this->push($device, $users);

        return [
            'pull' => $pullCommands,
            'push' => $pushCommands,
        ];
    }

    /**
     * Update an employee's details locally and push the updated record to the device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string  $pin  Employee PIN.
     * @param  array<string, mixed>  $attributes  Fields to update ('name', 'card_number', 'password', 'privilege').
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function updateUser(
        ZkDevice|string $device,
        string $pin,
        array $attributes,
    ): ZkDeviceCommand {
        $zkDevice = $this->resolveDevice($device);

        $user = ZkUser::where('pin', $pin)->first();
        if ($user !== null) {
            $user->update($attributes);
        } else {
            $user = ZkUser::create(array_merge(['pin' => $pin], $attributes));
        }

        $name = (string) ($user->name ?? "User {$pin}");
        $privilege = (int) $user->privilege;
        $card = (string) ($user->card_number ?? '');
        $password = (string) ($user->password ?? '');

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->updateUser($pin, $name, $privilege, $card, $password),
            'DATA'
        );
    }

    /**
     * Delete an employee from the database and queue a removal command to the device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string  $pin  User PIN / ID on the terminal.
     * @param  bool  $deleteFromDatabase  Whether to also delete the local database record (default: true).
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function deleteUser(
        ZkDevice|string $device,
        string $pin,
        bool $deleteFromDatabase = true,
    ): ZkDeviceCommand {
        $zkDevice = $this->resolveDevice($device);

        if ($deleteFromDatabase) {
            ZkUser::where('pin', $pin)->delete();
        }

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->deleteUser($pin),
            'DATA'
        );
    }

    /**
     * Bulk delete employees from the database and queue removal commands to the device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  array<int, string>  $pins  Array of employee PINs to delete.
     * @param  bool  $deleteFromDatabase  Whether to also delete local database records (default: true).
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function deleteUsers(
        ZkDevice|string $device,
        array $pins,
        bool $deleteFromDatabase = true,
    ): array {
        $zkDevice = $this->resolveDevice($device);

        if ($deleteFromDatabase) {
            ZkUser::whereIn('pin', $pins)->delete();
        }

        $commands = [];
        foreach ($pins as $pin) {
            $commands[] = $this->commandBuilder->deleteUser((string) $pin);
        }

        return $this->queueManager->enqueueBatch($zkDevice, $commands, 'DATA');
    }

    /**
     * Retrieve all registered employees from the database.
     *
     * @return Collection<int, ZkUser> Collection of user models.
     */
    public function getUsers(): Collection
    {
        return ZkUser::all();
    }

    /**
     * Retrieve a specific employee by PIN along with biometric templates and authorizations.
     *
     * @param  string  $pin  Employee PIN.
     * @return ZkUser|null The user model if found, or null.
     */
    public function getUser(string $pin): ?ZkUser
    {
        return ZkUser::with(['biodata', 'authorizations'])->where('pin', $pin)->first();
    }

    /**
     * Query attendance punch logs with optional device filtering.
     *
     * @param  ZkDevice|string|null  $device  Optional target device filter.
     * @return Collection<int, ZkAttendanceLog> Collection of matching attendance logs.
     */
    public function getAttendanceLogs(ZkDevice|string|null $device = null): Collection
    {
        $query = ZkAttendanceLog::with(['device', 'user'])->latest('recorded_at');

        if ($device !== null) {
            $zkDevice = $this->resolveDevice($device);
            $query->where('device_id', $zkDevice->id);
        }

        return $query->get();
    }

    /**
     * Query attendance punch logs for a specific employee.
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
        $query = ZkAttendanceLog::with('device')
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
     * Query attendance punch logs within a specific date range.
     *
     * @param  string  $startDate  Start date (Y-m-d or datetime).
     * @param  string|null  $endDate  Optional end date (defaults to end of startDate).
     * @param  ZkDevice|string|null  $device  Optional target device filter.
     * @return Collection<int, ZkAttendanceLog> Collection of matching attendance logs.
     */
    public function getAttendanceLogsByDate(
        string $startDate,
        ?string $endDate = null,
        ZkDevice|string|null $device = null,
    ): Collection {
        $query = ZkAttendanceLog::with(['device', 'user'])->latest('recorded_at');

        if ($device !== null) {
            $zkDevice = $this->resolveDevice($device);
            $query->where('device_id', $zkDevice->id);
        }

        if ($endDate !== null) {
            $query->whereBetween('recorded_at', [$startDate, $endDate]);
        } else {
            $query->whereDate('recorded_at', $startDate);
        }

        return $query->get();
    }

    /**
     * Query today's attendance punch logs.
     *
     * @param  ZkDevice|string|null  $device  Optional target device filter.
     * @return Collection<int, ZkAttendanceLog> Collection of matching attendance logs for today.
     */
    public function getTodayAttendanceLogs(ZkDevice|string|null $device = null): Collection
    {
        $query = ZkAttendanceLog::with(['device', 'user'])
            ->whereDate('recorded_at', today())
            ->latest('recorded_at');

        if ($device !== null) {
            $zkDevice = $this->resolveDevice($device);
            $query->where('device_id', $zkDevice->id);
        }

        return $query->get();
    }

    /**
     * Queue a command to clear/delete all attendance logs stored on the terminal.
     *
     * Wire command: `CLEAR LOG`
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function clearAttendanceLogs(ZkDevice|string $device): ZkDeviceCommand
    {
        $zkDevice = $this->resolveDevice($device);

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->clearLogs(),
            'CLEAR'
        );
    }

    /**
     * Queue a command to insert or update an attendance log on a target device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string  $pin  User PIN / ID associated with the punch.
     * @param  string  $timestamp  Formatted punch timestamp (Y-m-d H:i:s).
     * @param  int  $status  Punch state code (default: 0).
     * @param  int  $verifyMode  Verification modality code (default: 1).
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function syncAttendanceLog(
        ZkDevice|string $device,
        string $pin,
        string $timestamp,
        int $status = 0,
        int $verifyMode = 1,
    ): ZkDeviceCommand {
        $zkDevice = $this->resolveDevice($device);

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->updateAttendanceLog($pin, $timestamp, $status, $verifyMode),
            'DATA'
        );
    }

    /**
     * Queue commands to batch push multiple attendance logs to a target device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  Collection<int, ZkAttendanceLog>|array<int, ZkAttendanceLog>|null  $logs  Attendance logs to push, or null for all.
     * @return array<int, ZkDeviceCommand> Array of queued command records.
     */
    public function syncAttendanceLogs(
        ZkDevice|string $device,
        Collection|array|null $logs = null,
    ): array {
        $zkDevice = $this->resolveDevice($device);
        $logList = $logs ?? ZkAttendanceLog::where('device_id', $zkDevice->id)->get();

        $commands = [];
        foreach ($logList as $log) {
            $time = $log->recorded_at->format('Y-m-d H:i:s');
            $status = $log->status->value;
            $verify = $log->verify_mode->value;

            $commands[] = $this->commandBuilder->updateAttendanceLog(
                pin: (string) $log->pin,
                timestamp: $time,
                status: $status,
                verifyMode: $verify
            );
        }

        return $this->queueManager->enqueueBatch($zkDevice, $commands, 'DATA');
    }

    /**
     * Authorize user access with safe purge preceding update.
     *
     * Queues two sequential commands (purge existing followed by update) to avoid
     * the ZKTeco firmware authorization stacking bug.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string  $pin  User PIN / ID on the terminal.
     * @param  int  $doorId  Target door/relay index (default: 1).
     * @param  int  $timezoneId  Allowed timezone slot index (default: 1).
     * @return array<int, ZkDeviceCommand> Array containing the purge and update command records.
     */
    public function authorizeUserAccess(
        ZkDevice|string $device,
        string $pin,
        int $doorId = 1,
        int $timezoneId = 1,
    ): array {
        $zkDevice = $this->resolveDevice($device);
        $commands = $this->commandBuilder->authorizeAccess($pin, $doorId, $timezoneId);

        return $this->queueManager->enqueueBatch($zkDevice, $commands, 'DATA');
    }

    /**
     * Queue a command to upload biometric template data to a target device.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  BiodataPayload  $biodata  Biometric template data transfer object.
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function syncBiodata(ZkDevice|string $device, BiodataPayload $biodata): ZkDeviceCommand
    {
        $zkDevice = $this->resolveDevice($device);

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->updateBiodata($biodata),
            'DATA'
        );
    }

    /**
     * Queue a command to trigger remote biometric enrollment on the physical terminal screen.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @param  string  $pin  User PIN / ID to enroll biometrics for.
     * @param  BioType  $type  Biometric modality to capture (default: VisibleLightFace).
     * @return ZkDeviceCommand The enqueued command record.
     */
    public function triggerRemoteEnrollment(
        ZkDevice|string $device,
        string $pin,
        BioType $type = BioType::VisibleLightFace,
    ): ZkDeviceCommand {
        $zkDevice = $this->resolveDevice($device);

        return $this->queueManager->enqueue(
            $zkDevice,
            $this->commandBuilder->enrollBio($pin, $type),
            'ENROLL'
        );
    }

    /**
     * Resolve a ZkDevice model from either an existing model or a serial number string.
     *
     * @param  ZkDevice|string  $device  Device model or serial number string.
     * @return ZkDevice Resolved device model.
     *
     * @throws ModelNotFoundException If device serial number is not found.
     */
    protected function resolveDevice(ZkDevice|string $device): ZkDevice
    {
        if ($device instanceof ZkDevice) {
            return $device;
        }

        return ZkDevice::where('serial_number', $device)->firstOrFail();
    }
}

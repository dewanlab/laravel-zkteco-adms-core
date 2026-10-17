<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Facades;

use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDeviceCommand;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\CommandQueueManager;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\DeviceCommandBuilder;
use DewanLab\LaravelZktecoAdmsCore\ZktecoAdmsCore;
use Illuminate\Support\Facades\Facade;

/**
 * Laravel Facade providing static access to {@see ZktecoAdmsCore}.
 *
 * Exposes high-level methods to interact with ZKTeco ADMS push devices, including
 * remote hardware commands (rebooting, relay triggers), access control provisioning,
 * user credential synchronization, and biometric template management.
 *
 * Usage examples:
 * ```php
 * use DewanLab\LaravelZktecoAdmsCore\Facades\ZktecoAdms;
 *
 * // Reboot a device
 * ZktecoAdms::rebootDevice('SN123');
 *
 * // Actuate door relay to unlock for 5 seconds
 * ZktecoAdms::unlockDoor('SN123', doorId: 1, durationSeconds: 5);
 *
 * // Provision a user onto the terminal
 * ZktecoAdms::syncUser('SN123', '1001', 'John Doe');
 *
 * // Authorize door access schedule
 * ZktecoAdms::authorizeUserAccess('SN123', pin: '1001', doorId: 1, timezoneId: 1);
 * ```
 *
 * @method static DeviceCommandBuilder builder()
 * @method static CommandQueueManager queue()
 * @method static \DewanLab\LaravelZktecoAdmsCore\Services\ScopedDeviceManager device(ZkDevice|string $device)
 * @method static ZkDeviceCommand rebootDevice(ZkDevice|string $device)
 * @method static ZkDeviceCommand unlockDoor(ZkDevice|string $device, int $doorId = 1, int $durationSeconds = 5)
 * @method static ZkDeviceCommand createUser(ZkDevice|string $device, string $pin, string $name, int $privilege = 0, string $card = '', string $password = '')
 * @method static array<int, ZkDeviceCommand> createUsers(ZkDevice|string $device, array<int, array<string, mixed>|\DewanLab\LaravelZktecoAdmsCore\Models\ZkUser>|\Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser> $users)
 * @method static ZkDeviceCommand syncUser(ZkDevice|string $device, string $pin, string $name, int $privilege = 0, string $card = '')
 * @method static array<int, ZkDeviceCommand> syncUsers(ZkDevice|string $device, \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser>|array<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser>|null $users = null)
 * @method static array<int, ZkDeviceCommand> push(ZkDevice|string $device, \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser>|array<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser>|null $users = null)
 * @method static ZkDeviceCommand pullUsers(ZkDevice|string $device)
 * @method static ZkDeviceCommand pullLogs(ZkDevice|string $device, ?string $startTime = null, ?string $endTime = null)
 * @method static array<int, ZkDeviceCommand> pull(ZkDevice|string $device)
 * @method static array{pull: array<int, ZkDeviceCommand>, push: array<int, ZkDeviceCommand>} sync(ZkDevice|string $device, \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser>|array<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser>|null $users = null)
 * @method static ZkDeviceCommand updateUser(ZkDevice|string $device, string $pin, array<string, mixed> $attributes)
 * @method static ZkDeviceCommand deleteUser(ZkDevice|string $device, string $pin, bool $deleteFromDatabase = true)
 * @method static array<int, ZkDeviceCommand> deleteUsers(ZkDevice|string $device, array<int, string> $pins, bool $deleteFromDatabase = true)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser> getUsers()
 * @method static \DewanLab\LaravelZktecoAdmsCore\Models\ZkUser|null getUser(string $pin)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog> getAttendanceLogs(ZkDevice|string|null $device = null)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog> getAttendanceLogsForUser(string $pin, ?string $fromDate = null, ?string $toDate = null)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog> getAttendanceLogsByDate(string $startDate, ?string $endDate = null, ZkDevice|string|null $device = null)
 * @method static \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog> getTodayAttendanceLogs(ZkDevice|string|null $device = null)
 * @method static ZkDeviceCommand clearAttendanceLogs(ZkDevice|string $device)
 * @method static ZkDeviceCommand syncAttendanceLog(ZkDevice|string $device, string $pin, string $timestamp, int $status = 0, int $verifyMode = 1)
 * @method static array<int, ZkDeviceCommand> syncAttendanceLogs(ZkDevice|string $device, \Illuminate\Database\Eloquent\Collection<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog>|array<int, \DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog>|null $logs = null)
 * @method static array<int, ZkDeviceCommand> authorizeUserAccess(ZkDevice|string $device, string $pin, int $doorId = 1, int $timezoneId = 1)
 * @method static ZkDeviceCommand syncBiodata(ZkDevice|string $device, BiodataPayload $biodata)
 * @method static ZkDeviceCommand triggerRemoteEnrollment(ZkDevice|string $device, string $pin, BioType $type = BioType::VisibleLightFace)
 *
 * @see ZktecoAdmsCore
 */
class ZktecoAdms extends Facade
{
    /**
     * Get the registered name of the component in the service container.
     *
     * @return string Fully qualified service class name.
     */
    protected static function getFacadeAccessor(): string
    {
        return ZktecoAdmsCore::class;
    }
}

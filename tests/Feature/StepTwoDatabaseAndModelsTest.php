<?php

use Carbon\CarbonImmutable;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Enums\CommandStatus;
use DewanLab\LaravelZktecoAdmsCore\Enums\DeviceStatus;
use DewanLab\LaravelZktecoAdmsCore\Enums\PunchState;
use DewanLab\LaravelZktecoAdmsCore\Enums\VerifyMode;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkBiodata;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDeviceCommand;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUser;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUserAuthorization;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

describe('Step 2: Database Schema & Eloquent Models Extreme Verification', function () {
    beforeEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->up();
    });

    afterEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->down();
    });

    test('all tables are created with the configured prefix', function () {
        $prefix = config('zkteco-adms-core.table_prefix');

        expect(Schema::hasTable($prefix.'devices'))->toBeTrue()
            ->and(Schema::hasTable($prefix.'users'))->toBeTrue()
            ->and(Schema::hasTable($prefix.'attendance_logs'))->toBeTrue()
            ->and(Schema::hasTable($prefix.'biodata'))->toBeTrue()
            ->and(Schema::hasTable($prefix.'user_authorizations'))->toBeTrue()
            ->and(Schema::hasTable($prefix.'device_commands'))->toBeTrue();
    });

    test('device creation and status updates', function () {
        $device = ZkDevice::create([
            'serial_number' => 'DEV_SN_9999',
            'name' => 'Main Gate MB560',
            'ip_address' => '192.168.1.100',
            'status' => DeviceStatus::Online,
            'timezone' => 'Asia/Dhaka',
            'options' => ['Realtime' => '1', 'Delay' => '30'],
        ]);

        expect($device->id)->toBeGreaterThan(0)
            ->and($device->status)->toBe(DeviceStatus::Online)
            ->and($device->options['Realtime'])->toBe('1');

        $device->markAsOnline('192.168.1.101');
        $device->refresh();

        expect($device->ip_address)->toBe('192.168.1.101')
            ->and($device->last_activity_at)->not->toBeNull();
    });

    test('attendance log enforces unique punch deduplication index', function () {
        $device = ZkDevice::create([
            'serial_number' => 'SN_ATT_01',
            'name' => 'Test Attendance Device',
        ]);

        $punchTime = CarbonImmutable::parse('2026-10-02 08:30:00');

        $log1 = ZkAttendanceLog::create([
            'device_id' => $device->id,
            'pin' => '1001',
            'recorded_at' => $punchTime,
            'status' => PunchState::CheckIn,
            'verify_mode' => VerifyMode::VisibleLightFace,
        ]);

        expect($log1->id)->toBeGreaterThan(0);

        // Attempt duplicate insert with same (device_id, pin, recorded_at)
        expect(fn () => ZkAttendanceLog::create([
            'device_id' => $device->id,
            'pin' => '1001',
            'recorded_at' => $punchTime,
            'status' => PunchState::CheckIn,
            'verify_mode' => VerifyMode::VisibleLightFace,
        ]))->toThrow(QueryException::class);
    });

    test('biodata table handles hybrid templates with unique composite constraints', function () {
        $user = ZkUser::create([
            'pin' => '2001',
            'name' => 'John Doe',
        ]);

        $face = ZkBiodata::create([
            'pin' => '2001',
            'bio_type' => BioType::VisibleLightFace,
            'template_index' => 0,
            'template' => 'ENCODED_FACE_MESH_64',
            'is_valid' => true,
        ]);

        expect($face->bio_type)->toBe(BioType::VisibleLightFace)
            ->and($user->biodata()->count())->toBe(1);

        // Duplicate index for same pin and bio_type must fail
        expect(fn () => ZkBiodata::create([
            'pin' => '2001',
            'bio_type' => BioType::VisibleLightFace,
            'template_index' => 0,
            'template' => 'ANOTHER_TEMPLATE',
        ]))->toThrow(QueryException::class);
    });

    test('authorizations table links user to doors and timezones', function () {
        $user = ZkUser::create([
            'pin' => '3001',
            'name' => 'Alice Security',
        ]);

        $auth = ZkUserAuthorization::create([
            'pin' => '3001',
            'door_id' => 1,
            'timezone_id' => 2,
        ]);

        expect($auth->door_id)->toBe(1)
            ->and($user->authorizations()->count())->toBe(1);

        expect(fn () => ZkUserAuthorization::create([
            'pin' => '3001',
            'door_id' => 1,
            'timezone_id' => 2,
        ]))->toThrow(QueryException::class);
    });

    test('device commands lifecycle transitions', function () {
        $device = ZkDevice::create(['serial_number' => 'SN_CMD_TEST']);

        $cmd = ZkDeviceCommand::create([
            'device_id' => $device->id,
            'command_id' => 101,
            'command_string' => 'CONTROL DEVICE 01010105',
            'status' => CommandStatus::Pending,
            'queued_at' => now(),
        ]);

        expect($cmd->status)->toBe(CommandStatus::Pending);

        $cmd->markAsSent();
        $cmd->refresh();
        expect($cmd->status)->toBe(CommandStatus::Sent)
            ->and($cmd->sent_at)->not->toBeNull();

        $cmd->markAsAcknowledged(0);
        $cmd->refresh();
        expect($cmd->status)->toBe(CommandStatus::Acknowledged)
            ->and($cmd->return_code)->toBe(0)
            ->and($cmd->acknowledged_at)->not->toBeNull();
    });
});

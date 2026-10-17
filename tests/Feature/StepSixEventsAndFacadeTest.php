<?php

use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Enums\CommandStatus;
use DewanLab\LaravelZktecoAdmsCore\Events\AttendanceLogged;
use DewanLab\LaravelZktecoAdmsCore\Events\CommandAcknowledged;
use DewanLab\LaravelZktecoAdmsCore\Events\CommandFailed;
use DewanLab\LaravelZktecoAdmsCore\Events\UserSynchronized;
use DewanLab\LaravelZktecoAdmsCore\Facades\ZktecoAdms;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUser;
use Illuminate\Support\Facades\Event;

describe('Step 6: Facade & Lifecycle Events Extreme Verification', function () {
    beforeEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->up();
    });

    afterEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->down();
    });

    test('ZktecoAdms Facade queues reboot, unlock door, and biodata commands cleanly', function () {
        $device = ZkDevice::create(['serial_number' => 'FACADE_DEV_01']);

        $rebootCmd = ZktecoAdms::rebootDevice($device);
        expect($rebootCmd->command_string)->toBe('REBOOT')
            ->and($rebootCmd->status)->toBe(CommandStatus::Pending);

        $unlockCmd = ZktecoAdms::unlockDoor('FACADE_DEV_01', 1, 5);
        expect($unlockCmd->command_string)->toBe('CONTROL DEVICE 010101FF05');

        $bioCmd = ZktecoAdms::syncBiodata('FACADE_DEV_01', new BiodataPayload(
            pin: '7777',
            bioType: BioType::VisibleLightFace,
            index: 0,
            template: 'FACE64',
        ));
        expect($bioCmd->command_string)->toContain('DATA UPDATE biodata Pin=7777');

        // Test deleteUser
        $deleteCmd = ZktecoAdms::deleteUser('FACADE_DEV_01', '7777');
        expect($deleteCmd->command_string)->toBe('DATA DELETE USERINFO PIN=7777');

        // Test syncAttendanceLog
        $attCmd = ZktecoAdms::syncAttendanceLog('FACADE_DEV_01', '7777', '2026-10-14 10:00:00', 0, 1);
        expect($attCmd->command_string)->toBe("DATA UPDATE ATTLOG PIN=7777\tTime=2026-10-14 10:00:00\tStatus=0\tVerify=1");
    });

    test('ZktecoAdms Facade provides clean Employee CRUD and Attendance querying API', function () {
        $device = ZkDevice::create(['serial_number' => 'FACADE_DEV_CRUD']);

        // 1. Create single user
        $cmdCreate = ZktecoAdms::createUser('FACADE_DEV_CRUD', pin: '6001', name: 'Alice Smith', privilege: 0, card: 'CARD123', password: '999');
        expect($cmdCreate->command_string)->toBe("DATA UPDATE USERINFO PIN=6001\tName=Alice Smith\tPri=0\tCard=CARD123\tPasswd=999\tGrp=1\tTZ=0")
            ->and(ZkUser::where('pin', '6001')->first()?->name)->toBe('Alice Smith');

        // 2. Bulk create users
        $cmdBulk = ZktecoAdms::createUsers('FACADE_DEV_CRUD', [
            ['pin' => '6002', 'name' => 'Bob Jones', 'card' => 'CARD456'],
            ['pin' => '6003', 'name' => 'Charlie Brown'],
        ]);
        expect($cmdBulk)->toHaveCount(2)
            ->and(ZkUser::where('pin', '6002')->first()?->card_number)->toBe('CARD456')
            ->and(ZkUser::where('pin', '6003')->first()?->name)->toBe('Charlie Brown');

        // 3. Update single user
        $cmdUpdate = ZktecoAdms::updateUser('FACADE_DEV_CRUD', '6001', ['name' => 'Alice Updated', 'card_number' => 'NEWCARD']);
        expect($cmdUpdate->command_string)->toContain('Alice Updated')
            ->and(ZkUser::where('pin', '6001')->first()?->name)->toBe('Alice Updated');

        // 4. Get all users and specific user
        expect(ZktecoAdms::getUsers())->toHaveCount(3)
            ->and(ZktecoAdms::getUser('6001')?->name)->toBe('Alice Updated')
            ->and(ZktecoAdms::getUser('9999'))->toBeNull();

        // 5. Query attendance logs
        $todayLog = ZkAttendanceLog::create([
            'device_id' => $device->id,
            'pin' => '6001',
            'recorded_at' => now(),
            'status' => 0,
            'verify_mode' => 1,
        ]);
        $yesterdayLog = ZkAttendanceLog::create([
            'device_id' => $device->id,
            'pin' => '6002',
            'recorded_at' => now()->subDay(),
            'status' => 1,
            'verify_mode' => 15,
        ]);

        expect(ZktecoAdms::getAttendanceLogs())->toHaveCount(2)
            ->and(ZktecoAdms::getAttendanceLogs('FACADE_DEV_CRUD'))->toHaveCount(2)
            ->and(ZktecoAdms::getAttendanceLogsForUser('6001'))->toHaveCount(1)
            ->and(ZktecoAdms::getTodayAttendanceLogs())->toHaveCount(1)
            ->and(ZktecoAdms::getAttendanceLogsByDate(now()->subDay()->format('Y-m-d')))->toHaveCount(1);

        // 6. Delete single and bulk
        $cmdDelSingle = ZktecoAdms::deleteUser('FACADE_DEV_CRUD', '6001');
        expect($cmdDelSingle->command_string)->toBe('DATA DELETE USERINFO PIN=6001')
            ->and(ZkUser::where('pin', '6001')->first())->toBeNull();

        $cmdDelBulk = ZktecoAdms::deleteUsers('FACADE_DEV_CRUD', ['6002', '6003']);
        expect($cmdDelBulk)->toHaveCount(2)
            ->and(ZkUser::whereIn('pin', ['6002', '6003'])->get())->toHaveCount(0);
    });

    test('ZktecoAdms::device fluent API allows chaining without passing device argument repeatedly', function () {
        $zkDevice = ZkDevice::create(['serial_number' => 'FLUENT_DEV_01']);

        $dev = ZktecoAdms::device('FLUENT_DEV_01');
        expect($dev->getDevice()->serial_number)->toBe('FLUENT_DEV_01');

        // 1. Create single user fluently
        $cmdCreate = $dev->createUser('5001', 'Fluent Alice', card: 'F123', password: '777');
        expect($cmdCreate->command_string)->toBe("DATA UPDATE USERINFO PIN=5001\tName=Fluent Alice\tPri=0\tCard=F123\tPasswd=777\tGrp=1\tTZ=0")
            ->and(ZkUser::where('pin', '5001')->first()?->name)->toBe('Fluent Alice');

        // 2. Bulk create fluently
        $cmdBulk = $dev->createUsers([
            ['pin' => '5002', 'name' => 'Fluent Bob'],
        ]);
        expect($cmdBulk)->toHaveCount(1)
            ->and(ZkUser::where('pin', '5002')->first()?->name)->toBe('Fluent Bob');

        // 3. Update fluently
        $cmdUpdate = $dev->updateUser('5001', ['name' => 'Fluent Alice Updated']);
        expect($cmdUpdate->command_string)->toContain('Fluent Alice Updated')
            ->and(ZkUser::where('pin', '5001')->first()?->name)->toBe('Fluent Alice Updated');

        // 4. Query attendance logs fluently
        ZkAttendanceLog::create([
            'device_id' => $zkDevice->id,
            'pin' => '5001',
            'recorded_at' => now(),
            'status' => 0,
            'verify_mode' => 1,
        ]);
        expect($dev->getAttendanceLogs())->toHaveCount(1)
            ->and($dev->getTodayAttendanceLogs())->toHaveCount(1)
            ->and($dev->getAttendanceLogsForUser('5001'))->toHaveCount(1);

        // 5. Hardware controls fluently
        $rebootCmd = $dev->reboot();
        expect($rebootCmd->command_string)->toBe('REBOOT');

        $unlockCmd = $dev->unlockDoor(1, 5);
        expect($unlockCmd->command_string)->toBe('CONTROL DEVICE 010101FF05');

        $clearCmd = $dev->clearAttendanceLogs();
        expect($clearCmd->command_string)->toBe('CLEAR LOG');

        // 6. Delete fluently
        $delCmd = $dev->deleteUser('5001');
        expect($delCmd->command_string)->toBe('DATA DELETE USERINFO PIN=5001')
            ->and(ZkUser::where('pin', '5001')->first())->toBeNull();

        // 7. push, pull, pullUsers, pullLogs, and sync fluently
        ZkUser::create(['pin' => '5003', 'name' => 'PushUser']);
        $pushCmds = $dev->push();
        expect($pushCmds)->toHaveCount(2) // 5002 and 5003 exist in DB
            ->and($pushCmds[1]->command_string)->toContain('DATA UPDATE USERINFO PIN=5003');

        $pullUsersCmd = $dev->pullUsers();
        expect($pullUsersCmd->command_string)->toBe('DATA QUERY USERINFO');

        $pullLogsCmd = $dev->pullLogs();
        expect($pullLogsCmd->command_string)->toBe('DATA QUERY ATTLOG');
        $zkDevice->refresh();
        expect($zkDevice->options)->toHaveKey('force_pull_logs');

        $pullAll = $dev->pull();
        expect($pullAll)->toHaveCount(2);

        $syncRes = $dev->sync();
        expect($syncRes)->toHaveKeys(['pull', 'push'])
            ->and($syncRes['pull'])->toHaveCount(2)
            ->and($syncRes['push'])->toHaveCount(2);
    });

    test('ZktecoAdms Facade can batch sync users and attendance logs', function () {
        $device = ZkDevice::create(['serial_number' => 'FACADE_DEV_BATCH']);

        // Create sample users and logs
        ZkUser::create([
            'pin' => '8001',
            'name' => 'Batch User 1',
        ]);
        ZkUser::create([
            'pin' => '8002',
            'name' => 'Batch User 2',
        ]);

        $userCmds = ZktecoAdms::syncUsers('FACADE_DEV_BATCH');
        expect($userCmds)->toHaveCount(2)
            ->and($userCmds[0]->command_string)->toContain('DATA UPDATE USERINFO PIN=8001')
            ->and($userCmds[1]->command_string)->toContain('DATA UPDATE USERINFO PIN=8002');

        $log1 = ZkAttendanceLog::create([
            'device_id' => $device->id,
            'pin' => '8001',
            'recorded_at' => now(),
            'status' => 0,
            'verify_mode' => 1,
        ]);

        $logCmds = ZktecoAdms::syncAttendanceLogs('FACADE_DEV_BATCH', [$log1]);
        expect($logCmds)->toHaveCount(1)
            ->and($logCmds[0]->command_string)->toContain('DATA UPDATE ATTLOG PIN=8001');

        $clearCmd = ZktecoAdms::clearAttendanceLogs('FACADE_DEV_BATCH');
        expect($clearCmd->command_string)->toBe('CLEAR LOG');
    });

    test('AttendanceLogged event dispatches upon receiving ATTLOG', function () {
        Event::fake([AttendanceLogged::class]);

        $rawAttLog = "9001\t2026-10-02 09:00:00\t0\t15\t\t\n";

        $this->call(
            'POST',
            '/iclock/cdata?SN=EVENT_DEV_01&table=ATTLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawAttLog
        );

        Event::assertDispatched(AttendanceLogged::class, function ($event) {
            return $event->attendanceLog->pin === '9001'
                && $event->device->serial_number === 'EVENT_DEV_01';
        });
    });

    test('UserSynchronized event dispatches upon receiving OPERLOG', function () {
        Event::fake([UserSynchronized::class]);

        $rawOperLog = "PIN=9002\tName=Eve\tPri=0\tCard=112233\n";

        $this->call(
            'POST',
            '/iclock/cdata?SN=EVENT_DEV_02&table=OPERLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawOperLog
        );

        Event::assertDispatched(UserSynchronized::class, function ($event) {
            return $event->user->pin === '9002'
                && $event->user->name === 'Eve';
        });
    });

    test('CommandAcknowledged and CommandFailed events dispatch on ACK callback', function () {
        Event::fake([CommandAcknowledged::class, CommandFailed::class]);

        $device = ZkDevice::create(['serial_number' => 'EVENT_DEV_03']);
        $cmd1 = ZktecoAdms::rebootDevice($device);
        $cmd2 = ZktecoAdms::unlockDoor($device);

        // 1. Success ACK
        $this->call(
            'POST',
            '/iclock/devicecmd?SN=EVENT_DEV_03',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$cmd1->id}&Return=0&CMD=REBOOT\n"
        );

        Event::assertDispatched(CommandAcknowledged::class, function ($event) use ($cmd1) {
            return $event->command->id === $cmd1->id && $event->returnCode === 0;
        });

        // 2. Failure ACK
        $this->call(
            'POST',
            '/iclock/devicecmd?SN=EVENT_DEV_03',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$cmd2->id}&Return=-1002&CMD=CONTROL DEVICE\n"
        );

        Event::assertDispatched(CommandFailed::class, function ($event) use ($cmd2) {
            return $event->command->id === $cmd2->id && $event->returnCode === -1002;
        });
    });
});

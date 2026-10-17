<?php

use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Enums\CommandStatus;
use DewanLab\LaravelZktecoAdmsCore\Enums\DeviceStatus;
use DewanLab\LaravelZktecoAdmsCore\Enums\PunchState;
use DewanLab\LaravelZktecoAdmsCore\Enums\VerifyMode;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkBiodata;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUser;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\CommandQueueManager;

describe('Step 5: HTTP Endpoints & Device Controller Extreme Verification', function () {
    beforeEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->up();
    });

    afterEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->down();
    });

    test('Endpoint /iclock/cdata returns 400 when SN is missing', function () {
        $res = $this->get('/iclock/cdata');
        $res->assertStatus(400);
    });

    test('Initial handshake GET /iclock/cdata returns GET OPTION FROM payload', function () {
        $res = $this->get('/iclock/cdata?SN=DEVICE_SN_123');

        $res->assertStatus(200);
        $content = $res->getContent();

        expect($content)->toStartWith("GET OPTION FROM: DEVICE_SN_123\n")
            ->and($content)->toContain("Realtime=1\n")
            ->and($content)->toContain("Delay=30\n");

        $device = ZkDevice::where('serial_number', 'DEVICE_SN_123')->first();
        expect($device)->not->toBeNull()
            ->and($device->status)->toBe(DeviceStatus::Online);
    });

    test('Attendance ingestion POST /iclock/cdata?table=ATTLOG parses and deduplicates punches', function () {
        $rawAttLog = "1001\t2026-10-02 08:30:00\t0\t15\t\t\n"
                   ."1002\t2026-10-02 08:31:00\t0\t1\t\t\n";

        // First push
        $res1 = $this->call(
            'POST',
            '/iclock/cdata?SN=ATT_DEVICE_01&table=ATTLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawAttLog
        );

        $res1->assertStatus(200);
        expect($res1->getContent())->toBe("OK: 2\n")
            ->and(ZkAttendanceLog::count())->toBe(2);

        $log = ZkAttendanceLog::where('pin', '1001')->first();
        expect($log->verify_mode)->toBe(VerifyMode::VisibleLightFace)
            ->and($log->status)->toBe(PunchState::CheckIn);

        // Immediate duplicate push must be idempotently ignored
        $res2 = $this->call(
            'POST',
            '/iclock/cdata?SN=ATT_DEVICE_01&table=ATTLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawAttLog
        );

        $res2->assertStatus(200);
        expect($res2->getContent())->toBe("OK: 0\n")
            ->and(ZkAttendanceLog::count())->toBe(2);
    });

    test('OPERLOG ingestion POST /iclock/cdata?table=OPERLOG creates and updates users', function () {
        $rawOperLog = "PIN=8888\tName=Robert\tPri=0\tCard=33445566\n";

        $res = $this->call(
            'POST',
            '/iclock/cdata?SN=OPER_DEV_01&table=OPERLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawOperLog
        );

        $res->assertStatus(200);

        $user = ZkUser::where('pin', '8888')->first();
        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('Robert')
            ->and($user->card_number)->toBe('33445566');
    });

    test('BIODATA ingestion POST /iclock/cdata?table=BIODATA saves hybrid face templates', function () {
        $rawBio = "Pin=9999\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=9\tTmp=BASE64FACE\n";

        $res = $this->call(
            'POST',
            '/iclock/cdata?SN=BIO_DEV_01&table=BIODATA',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawBio
        );

        $res->assertStatus(200);

        $bio = ZkBiodata::where('pin', '9999')->first();
        expect($bio)->not->toBeNull()
            ->and($bio->bio_type)->toBe(BioType::VisibleLightFace)
            ->and($bio->template)->toBe('BASE64FACE');
    });

    test('Polling GET /iclock/getrequest drains queued commands in wire syntax', function () {
        $device = ZkDevice::create(['serial_number' => 'POLL_DEV_01']);
        $queue = app(CommandQueueManager::class);

        $cmd = $queue->enqueue($device, 'CONTROL DEVICE 010101FF05', 'RELAY');

        $res = $this->get('/iclock/getrequest?SN=POLL_DEV_01');

        $res->assertStatus(200);
        expect($res->getContent())->toBe("C:{$cmd->id}:CONTROL DEVICE 010101FF05\n");

        $cmd->refresh();
        expect($cmd->status)->toBe(CommandStatus::Sent);
    });

    test('Execution ACK POST /iclock/devicecmd confirms commands asynchronously', function () {
        $device = ZkDevice::create(['serial_number' => 'ACK_DEV_01']);
        $queue = app(CommandQueueManager::class);

        $cmd = $queue->enqueue($device, 'REBOOT', 'REBOOT');

        $res = $this->call(
            'POST',
            '/iclock/devicecmd?SN=ACK_DEV_01',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            "ID={$cmd->id}&Return=0&CMD=REBOOT\n"
        );

        $res->assertStatus(200);

        $cmd->refresh();
        expect($cmd->status)->toBe(CommandStatus::Acknowledged)
            ->and($cmd->return_code)->toBe(0);
    });

    test('Query responses POST /iclock/querydata parses USERINFO records and imports users', function () {
        ZkDevice::create(['serial_number' => 'QUERY_DEV_01']);
        $rawQueryUsers = "PIN=8888\tName=QueryUser\tPri=0\tCard=998877\tPasswd=1234\n";

        $res = $this->call(
            'POST',
            '/iclock/querydata?SN=QUERY_DEV_01&tablename=USERINFO',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawQueryUsers
        );

        $res->assertStatus(200);

        $user = ZkUser::where('pin', '8888')->first();
        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('QueryUser')
            ->and($user->card_number)->toBe('998877');
    });

    test('Handshake resets Stamp to 0 when force_pull_logs is set on device options', function () {
        $device = ZkDevice::create([
            'serial_number' => 'STAMP_DEV_01',
            'options' => ['force_pull_logs' => true],
        ]);

        $res = $this->get('/iclock/cdata?SN=STAMP_DEV_01');
        $res->assertStatus(200);

        expect($res->getContent())->toContain('Stamp=0');

        $device->refresh();
        expect($device->options)->not->toHaveKey('force_pull_logs');
    });
});

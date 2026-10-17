<?php

use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\DTOs\CommandAck;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Enums\CommandStatus;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\CommandQueueManager;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\DeviceCommandBuilder;

describe('Step 4: Command Builder & Asynchronous Queue Manager Extreme Verification', function () {
    beforeEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->up();
    });

    afterEach(function () {
        $migration = require __DIR__.'/../../database/migrations/2026_10_03_000001_create_zkteco_adms_tables.php';
        $migration->down();
    });

    test('DeviceCommandBuilder generates compliant ADMS wire command strings', function () {
        $builder = new DeviceCommandBuilder;

        expect($builder->reboot())->toBe('REBOOT')
            ->and($builder->checkTime())->toBe('CHECK')
            ->and($builder->clearLogs())->toBe('CLEAR LOG')
            ->and($builder->clearData())->toBe('CLEAR DATA')
            ->and($builder->deleteUser('1001'))->toBe('DATA DELETE USERINFO PIN=1001')
            ->and($builder->unlockDoor(1, 5))->toBe('CONTROL DEVICE 010101FF05')
            ->and($builder->unlockDoor(2, 10))->toBe('CONTROL DEVICE 010201FF0a')
            ->and($builder->cancelAlarm())->toBe('CONTROL DEVICE 0200000000')
            ->and($builder->enrollBio('1001', BioType::VisibleLightFace, 3, 1))
            ->toBe('ENROLL_BIO TYPE=9 NO=0 PIN=1001 RETRY=3 OVERWRITE=1 MODE=1');
    });

    test('DeviceCommandBuilder guarantees DELETE before UPDATE for access authorizations', function () {
        $builder = new DeviceCommandBuilder;
        $commands = $builder->authorizeAccess('5001', 1, 2);

        expect($commands)->toHaveCount(2)
            ->and($commands[0])->toBe('DATA DELETE userauthorize Pin=5001')
            ->and($commands[1])->toBe('DATA UPDATE userauthorize Pin=5001 AuthorizeTimezoneId=2 AuthorizeDoorId=1');
    });

    test('DeviceCommandBuilder generates correct biometric template updates', function () {
        $builder = new DeviceCommandBuilder;
        $biodata = new BiodataPayload(
            pin: '3001',
            bioType: BioType::Palm,
            index: 0,
            template: 'PALM_BASE64_TEMPLATE'
        );

        $cmd = $builder->updateBiodata($biodata);
        expect($cmd)->toStartWith('DATA UPDATE biodata Pin=3001')
            ->and($cmd)->toContain('Type=25')
            ->and($cmd)->toContain('Tmp=PALM_BASE64_TEMPLATE');
    });

    test('CommandQueueManager queues, drains to wire format, and handles ACK callbacks', function () {
        $device = ZkDevice::create(['serial_number' => 'ZK_CMD_QUEUE_SN']);
        $builder = new DeviceCommandBuilder;
        $queue = new CommandQueueManager;

        // 1. Enqueue single and batch commands
        $cmd1 = $queue->enqueue($device, $builder->reboot(), 'REBOOT');
        $cmd2List = $queue->enqueueBatch($device, $builder->authorizeAccess('1001', 1, 1));

        expect($cmd1->status)->toBe(CommandStatus::Pending)
            ->and($cmd2List)->toHaveCount(2);

        // 2. Drain to wire payload
        $wire = $queue->drainToWire($device);

        expect($wire)->toContain("C:{$cmd1->id}:REBOOT\n")
            ->and($wire)->toContain("C:{$cmd2List[0]->id}:DATA DELETE userauthorize Pin=1001\n")
            ->and($wire)->toContain("C:{$cmd2List[1]->id}:DATA UPDATE userauthorize Pin=1001 AuthorizeTimezoneId=1 AuthorizeDoorId=1\n");

        $cmd1->refresh();
        expect($cmd1->status)->toBe(CommandStatus::Sent)
            ->and($cmd1->sent_at)->not->toBeNull();

        // 3. Simulate ACK return code 0 (Success)
        $ackSuccess = new CommandAck(commandId: $cmd1->id, returnCode: 0, command: 'REBOOT');
        $queue->acknowledge($ackSuccess);

        $cmd1->refresh();
        expect($cmd1->status)->toBe(CommandStatus::Acknowledged)
            ->and($cmd1->return_code)->toBe(0);

        // 4. Simulate Failure return code -1002 (Deprecated command)
        $ackFail = new CommandAck(commandId: $cmd2List[0]->id, returnCode: -1002);
        $queue->acknowledge($ackFail);

        $cmd2List[0]->refresh();
        expect($cmd2List[0]->status)->toBe(CommandStatus::Failed)
            ->and($cmd2List[0]->return_code)->toBe(-1002)
            ->and($cmd2List[0]->error_message)->toContain('Deprecated Command');
    });
});

<?php

use Carbon\CarbonImmutable;
use DewanLab\LaravelZktecoAdmsCore\DTOs\AccessAuthorization;
use DewanLab\LaravelZktecoAdmsCore\DTOs\AttendancePunch;
use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\DTOs\CommandAck;
use DewanLab\LaravelZktecoAdmsCore\DTOs\HandshakeConfig;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Enums\CommandStatus;
use DewanLab\LaravelZktecoAdmsCore\Enums\PunchState;
use DewanLab\LaravelZktecoAdmsCore\Enums\VerifyMode;

describe('Step 1: Enums & DTOs Extreme Verification', function () {
    test('PunchState handles all integer boundaries and labels', function () {
        expect(PunchState::fromValue(0))->toBe(PunchState::CheckIn)
            ->and(PunchState::fromValue('1'))->toBe(PunchState::CheckOut)
            ->and(PunchState::fromValue(2))->toBe(PunchState::BreakOut)
            ->and(PunchState::fromValue(3))->toBe(PunchState::BreakIn)
            ->and(PunchState::fromValue(4))->toBe(PunchState::OvertimeIn)
            ->and(PunchState::fromValue(5))->toBe(PunchState::OvertimeOut)
            ->and(PunchState::fromValue(8))->toBe(PunchState::MealStart)
            ->and(PunchState::fromValue(999))->toBe(PunchState::Other)
            ->and(PunchState::fromValue('corrupted'))->toBe(PunchState::Other)
            ->and(PunchState::fromValue(null))->toBe(PunchState::CheckIn);

        expect(PunchState::CheckIn->label())->toBe('Check In')
            ->and(PunchState::CheckOut->label())->toBe('Check Out');
    });

    test('VerifyMode handles password, fingerprint, rfid, visible face, and palm', function () {
        expect(VerifyMode::fromValue(0))->toBe(VerifyMode::Password)
            ->and(VerifyMode::fromValue(1))->toBe(VerifyMode::Fingerprint)
            ->and(VerifyMode::fromValue(2))->toBe(VerifyMode::Card)
            ->and(VerifyMode::fromValue(15))->toBe(VerifyMode::VisibleLightFace)
            ->and(VerifyMode::fromValue('25'))->toBe(VerifyMode::Palm)
            ->and(VerifyMode::fromValue(99))->toBe(VerifyMode::Other);

        expect(VerifyMode::VisibleLightFace->label())->toBe('Visible Light Face')
            ->and(VerifyMode::Palm->label())->toBe('Palm Print');
    });

    test('BioType enum identifies hybrid biometrics', function () {
        expect(BioType::fromValue(1))->toBe(BioType::Fingerprint)
            ->and(BioType::fromValue(9))->toBe(BioType::VisibleLightFace)
            ->and(BioType::fromValue(25))->toBe(BioType::Palm)
            ->and(BioType::fromValue(26))->toBe(BioType::FingerVein)
            ->and(BioType::fromValue(100))->toBe(BioType::Unknown);
    });

    test('CommandStatus detects terminal states', function () {
        expect(CommandStatus::Pending->isCompleted())->toBeFalse()
            ->and(CommandStatus::Sent->isCompleted())->toBeFalse()
            ->and(CommandStatus::Acknowledged->isCompleted())->toBeTrue()
            ->and(CommandStatus::Failed->isCompleted())->toBeTrue();
    });

    test('AttendancePunch detects RTC clock resets to epoch 2000', function () {
        $driftedPunch = new AttendancePunch(
            pin: '1001',
            recordedAt: CarbonImmutable::parse('2000-01-01 00:05:00'),
            state: PunchState::CheckIn,
            verifyMode: VerifyMode::VisibleLightFace,
        );

        $validPunch = new AttendancePunch(
            pin: '1001',
            recordedAt: CarbonImmutable::parse('2026-10-02 08:30:00'),
            state: PunchState::CheckIn,
            verifyMode: VerifyMode::VisibleLightFace,
        );

        expect($driftedPunch->isPossibleRtcDrift())->toBeTrue()
            ->and($validPunch->isPossibleRtcDrift())->toBeFalse();

        expect($validPunch->toArray())->toHaveKeys([
            'pin', 'recorded_at', 'state', 'state_label', 'verify_mode', 'verify_mode_label',
        ]);
    });

    test('BiodataPayload formats wire command parameters for DATA UPDATE biodata', function () {
        $payload = new BiodataPayload(
            pin: '1001',
            bioType: BioType::VisibleLightFace,
            index: 0,
            template: 'BASE64FACEDATA12345',
            isValid: true,
        );

        $params = $payload->toCommandParams();
        expect($params)->toContain('Pin=1001')
            ->and($params)->toContain('Type=9')
            ->and($params)->toContain('Valid=1')
            ->and($params)->toContain('Tmp=BASE64FACEDATA12345');
    });

    test('CommandAck decodes known hardware error codes', function () {
        $success = new CommandAck(commandId: 10, returnCode: 0);
        $deprecated = new CommandAck(commandId: 11, returnCode: -1002);
        $badParam = new CommandAck(commandId: 12, returnCode: -30);
        $fwFail = new CommandAck(commandId: 13, returnCode: -3);

        expect($success->isSuccess())->toBeTrue()
            ->and($success->errorMessage())->toBeNull();

        expect($deprecated->isDeprecatedCommand())->toBeTrue()
            ->and($deprecated->errorMessage())->toContain('Deprecated Command');

        expect($badParam->isInvalidParameter())->toBeTrue()
            ->and($badParam->errorMessage())->toContain('Invalid Parameter');

        expect($fwFail->errorMessage())->toContain('Upgrade Failure');
    });

    test('HandshakeConfig generates compliant GET OPTION FROM wire payload', function () {
        $handshake = new HandshakeConfig(
            serialNumber: 'ZK123456789',
            stamp: 450,
            opStamp: 22,
            photoStamp: 0,
            errorDelay: 60,
            delay: 15,
            transTimes: '00:00;12:00',
            transInterval: 1,
            transFlag: '1111111111',
            realtime: 1,
            encrypt: 0,
            timezone: 'Asia/Dhaka',
        );

        $wire = $handshake->toWireString();

        expect($wire)->toStartWith("GET OPTION FROM: ZK123456789\n")
            ->and($wire)->toContain("Stamp=450\n")
            ->and($wire)->toContain("OpStamp=22\n")
            ->and($wire)->toContain("Realtime=1\n")
            ->and($wire)->toContain("Delay=15\n")
            ->and($wire)->toContain("TimeZone=6\n");

        $numericHandshake = new HandshakeConfig(
            serialNumber: 'ZK123456789',
            timezone: 6,
        );
        expect($numericHandshake->toWireString())->toContain("TimeZone=6\n");
    });

    test('AccessAuthorization generates safety purge before update to avoid stacking bug', function () {
        $auth = new AccessAuthorization(pin: '5001', doorId: 2, timezoneId: 1);

        expect($auth->toPurgeCommand())->toBe('DATA DELETE userauthorize Pin=5001')
            ->and($auth->toUpdateCommand())->toBe('DATA UPDATE userauthorize Pin=5001 AuthorizeTimezoneId=1 AuthorizeDoorId=2');
    });
});

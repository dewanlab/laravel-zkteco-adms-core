<?php

use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;
use DewanLab\LaravelZktecoAdmsCore\Enums\PunchState;
use DewanLab\LaravelZktecoAdmsCore\Enums\VerifyMode;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\AttendanceParser;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\BiodataParser;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\CommandAckParser;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\OperationLogParser;

describe('Step 3: Wire Parsers Extreme Verification', function () {
    test('AttendanceParser parses standard and malformed ATTLOG strings', function () {
        $parser = new AttendanceParser;

        $rawAttLog = <<<RAW
1001\t2026-10-02 08:30:00\t0\t15\t\t0\t0\t\t\t43
1002\t2026-10-02 17:15:22\t1\t1\tDEPT1
\t\t\t
corrupted_line_without_tabs
1003\tinvalid-timestamp-value\t0\t2
   1004\t2026-10-02 12:00:00\t2\t2\t\t
RAW;

        $punches = $parser->parse($rawAttLog, 'SN_ZK_001', 'Asia/Dhaka');

        expect($punches)->toHaveCount(3)
            // Punch 1 (Visible Light Face)
            ->and($punches[0]->pin)->toBe('1001')
            ->and($punches[0]->recordedAt->format('Y-m-d H:i:s'))->toBe('2026-10-02 08:30:00')
            ->and($punches[0]->recordedAt->timezoneName)->toBe('Asia/Dhaka')
            ->and($punches[0]->state)->toBe(PunchState::CheckIn)
            ->and($punches[0]->verifyMode)->toBe(VerifyMode::VisibleLightFace)
            ->and($punches[0]->serialNumber)->toBe('SN_ZK_001')
            // Punch 2 (Fingerprint CheckOut)
            ->and($punches[1]->pin)->toBe('1002')
            ->and($punches[1]->state)->toBe(PunchState::CheckOut)
            ->and($punches[1]->verifyMode)->toBe(VerifyMode::Fingerprint)
            // Punch 3 (BreakOut with extra spacing)
            ->and($punches[2]->pin)->toBe('1004')
            ->and($punches[2]->state)->toBe(PunchState::BreakOut);
    });

    test('CommandAckParser parses single and multiline responses including firmware errors', function () {
        $parser = new CommandAckParser;

        $multiline = <<<'RAW'
ID=295&Return=0&CMD=DATA UPDATE
ID=296&Return=-1002&CMD=USER ADD
ID=297&Return=-30&CMD=DATA UPDATE biodata
RAW;

        $acks = $parser->parse($multiline);

        expect($acks)->toHaveCount(3)
            ->and($acks[0]->commandId)->toBe(295)
            ->and($acks[0]->isSuccess())->toBeTrue()
            ->and($acks[1]->commandId)->toBe(296)
            ->and($acks[1]->isDeprecatedCommand())->toBeTrue()
            ->and($acks[2]->commandId)->toBe(297)
            ->and($acks[2]->isInvalidParameter())->toBeTrue();
    });

    test('OperationLogParser extracts key-value tokens from OPERLOG', function () {
        $parser = new OperationLogParser;

        $rawOperLog = "PIN=1001\tName=Johny Deep\tPri=0\tPasswd=\tCard=998877\tGrp=1\tTZ=1\n"
                    ."PIN=1002\tName=Alice\tPri=14\tPasswd=1234\tCard=\n";

        $records = $parser->parse($rawOperLog);

        expect($records)->toHaveCount(2)
            ->and($records[0]['PIN'])->toBe('1001')
            ->and($records[0]['Name'])->toBe('Johny Deep')
            ->and($records[0]['Card'])->toBe('998877')
            ->and($records[1]['PIN'])->toBe('1002')
            ->and($records[1]['Pri'])->toBe('14');
    });

    test('BiodataParser parses hybrid facial biometric push line', function () {
        $parser = new BiodataParser;

        $line = "Pin=5001\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=9\tSize=850\tTmp=BASE64FACEDATA==";
        $biodata = $parser->parseLine($line);

        expect($biodata)->not->toBeNull()
            ->and($biodata->pin)->toBe('5001')
            ->and($biodata->bioType)->toBe(BioType::VisibleLightFace)
            ->and($biodata->template)->toBe('BASE64FACEDATA==')
            ->and($biodata->size)->toBe(850)
            ->and($biodata->isValid)->toBeTrue();
    });
});

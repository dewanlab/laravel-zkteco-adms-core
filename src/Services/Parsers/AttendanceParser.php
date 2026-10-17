<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Services\Parsers;

use Carbon\CarbonImmutable;
use DateTimeZone;
use DewanLab\LaravelZktecoAdmsCore\DTOs\AttendancePunch;
use DewanLab\LaravelZktecoAdmsCore\Enums\PunchState;
use DewanLab\LaravelZktecoAdmsCore\Enums\VerifyMode;
use Exception;

/**
 * Parses raw ATTLOG tab-delimited payloads pushed by ZKTeco devices to /iclock/cdata?table=ATTLOG.
 *
 * In the ZKTeco ADMS Push Protocol, biometric terminals periodically upload attendance records
 * as tab-separated plain text via HTTP POST requests. Each line represents a single verification
 * or punch event recorded on the hardware terminal.
 *
 * Wire payload line format:
 * `PIN \t Time \t Status \t VerifyMode \t WorkCode \t Reserved...`
 *
 * Features & resilience handling:
 * - Multi-platform Line Breaks: Normalizes Windows (\r\n), legacy Mac (\r), and Unix (\n) line endings.
 * - Resilient Ingestion: Safely skips blank lines, malformed records (less than 2 tokens), empty PINs, and unparseable timestamps.
 * - Timezone Normalization: Parses the terminal's wall-clock timestamp using the device's configured timezone, falling back to UTC if invalid.
 * - Enum Conversion: Translates hardware status and verification codes into strongly-typed PunchState and VerifyMode enums.
 *
 * Example:
 * ```php
 * $parser = new AttendanceParser();
 * $punches = $parser->parse("1001\t2026-10-03 14:00:00\t0\t1\t0", serialNumber: 'CK123456', timezone: 'UTC');
 * ```
 */
class AttendanceParser
{
    /**
     * Parse a raw ATTLOG plain text payload into structured AttendancePunch DTOs.
     *
     * Format per line:
     * `PIN \t Time \t Status \t VerifyMode \t WorkCode \t Reserved...`
     *
     * @param  string  $payload  Raw plain text payload containing tab-delimited attendance log lines.
     * @param  string  $serialNumber  Device serial number to associate with each parsed punch.
     * @param  string  $timezone  Timezone identifier of the device used to interpret local punch timestamps.
     * @return array<int, AttendancePunch> List of parsed attendance punches.
     */
    public function parse(string $payload, string $serialNumber = '', string $timezone = 'UTC'): array
    {
        $punches = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($payload));

        if ($lines === false || $lines === []) {
            return [];
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception) {
            $tz = new DateTimeZone('UTC');
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $fields = explode("\t", $trimmed);
            if (count($fields) < 2) {
                continue;
            }

            $pin = trim($fields[0]);
            if ($pin === '') {
                continue;
            }

            $rawTime = trim($fields[1]);
            try {
                $recordedAt = CarbonImmutable::parse($rawTime, $tz);
            } catch (Exception) {
                continue;
            }

            $stateVal = $fields[2] ?? 0;
            $verifyVal = $fields[3] ?? 0;
            $workCode = trim($fields[4] ?? '');

            $punches[] = new AttendancePunch(
                pin: $pin,
                recordedAt: $recordedAt,
                state: PunchState::fromValue($stateVal),
                verifyMode: VerifyMode::fromValue($verifyVal),
                workCode: $workCode,
                serialNumber: $serialNumber,
                rawFields: $fields
            );
        }

        return $punches;
    }
}

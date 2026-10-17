<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\DTOs;

use Carbon\CarbonInterface;
use DewanLab\LaravelZktecoAdmsCore\Enums\PunchState;
use DewanLab\LaravelZktecoAdmsCore\Enums\VerifyMode;

/**
 * Immutable value object representing a single biometric punch event from a ZKTeco terminal's ATTLOG table.
 *
 * In the ZKTeco ADMS Push Protocol, edge devices push attendance transaction batches
 * to the server via `POST /iclock/cdata?SN=<serial>&table=ATTLOG`. The body contains
 * raw newline-delimited rows formatted as tab-delimited tokens:
 *
 * ```text
 * PIN \t Time \t Status \t VerifyMode \t WorkCode \t Reserved...
 * 1001 \t 2026-10-03 08:30:00 \t 0 \t 1 \t 0 \t 0
 * ```
 *
 * Example:
 * ```php
 * $punch = new AttendancePunch(
 *     pin: '1001',
 *     recordedAt: CarbonImmutable::now(),
 *     state: PunchState::CheckIn,
 *     verifyMode: VerifyMode::Fingerprint,
 *     serialNumber: 'CK100012345'
 * );
 * ```
 */
final readonly class AttendancePunch
{
    /**
     * Create a new AttendancePunch instance.
     *
     * @param  string  $pin  User employee ID or badge number (PIN) on the terminal.
     * @param  CarbonInterface  $recordedAt  Timestamp when the punch was recorded by the terminal RTC.
     * @param  PunchState  $state  Punch status code (e.g. Check In, Check Out, Break, Overtime).
     * @param  VerifyMode  $verifyMode  Authentication modality used by the user (e.g. Fingerprint, Face).
     * @param  string  $workCode  Cost-center or work code entered during the punch.
     * @param  string  $serialNumber  Hardware serial number of the terminal that captured the punch.
     * @param  array<int, string>  $rawFields  Raw tab-delimited tokens extracted directly from the ATTLOG line.
     */
    public function __construct(
        public string $pin,
        public CarbonInterface $recordedAt,
        public PunchState $state = PunchState::CheckIn,
        public VerifyMode $verifyMode = VerifyMode::Password,
        public string $workCode = '',
        public string $serialNumber = '',
        public array $rawFields = [],
    ) {}

    /**
     * Determine whether the punch timestamp likely suffers from Real-Time Clock (RTC) drift.
     *
     * Unconfigured or power-cycled ZKTeco terminals with depleted CMOS batteries frequently
     * reset their internal hardware clock back to year 2000 or 1970 upon reboot.
     *
     * @param  int  $thresholdYear  Baseline year below which a timestamp is considered invalid (default: 2020).
     * @return bool True if the punch timestamp year is strictly before the threshold year.
     */
    public function isPossibleRtcDrift(int $thresholdYear = 2020): bool
    {
        return $this->recordedAt->year < $thresholdYear;
    }

    /**
     * Convert the punch event to an associative array suitable for serialization.
     *
     * @return array<string, mixed> Associative array representation of the punch.
     */
    public function toArray(): array
    {
        return [
            'pin' => $this->pin,
            'recorded_at' => $this->recordedAt->toDateTimeString(),
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'verify_mode' => $this->verifyMode->value,
            'verify_mode_label' => $this->verifyMode->label(),
            'work_code' => $this->workCode,
            'serial_number' => $this->serialNumber,
        ];
    }
}

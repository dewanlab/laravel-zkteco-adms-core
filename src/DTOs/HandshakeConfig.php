<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\DTOs;

/**
 * Generates the `GET OPTION FROM: <SerialNumber>` plaintext handshake response.
 *
 * In the ZKTeco ADMS Push Protocol, when an edge terminal connects or performs its
 * initial registration handshake via `GET|POST /iclock/cdata` or `/iclock/registry`,
 * the server responds with a plaintext configuration block.
 *
 * The response header specifies `GET OPTION FROM: <SerialNumber>`, followed by
 * newline-delimited key-value configuration directives that control the terminal's
 * polling interval, sync watermark cursors, real-time push behavior, and encryption settings.
 *
 * Example:
 * ```php
 * $config = new HandshakeConfig(
 *     serialNumber: 'CK100012345',
 *     delay: 30,
 *     realtime: 1
 * );
 * return response($config->toWireString(), 200, ['Content-Type' => 'text/plain']);
 * ```
 */
final readonly class HandshakeConfig
{
    /**
     * Create a new HandshakeConfig instance.
     *
     * @param  string  $serialNumber  Hardware serial number of the terminal receiving options.
     * @param  int  $stamp  Attendance log sync cursor/watermark (ATTLOG Stamp).
     * @param  int  $opStamp  Operation audit log sync cursor (OPLOG OpStamp).
     * @param  int  $photoStamp  Attendance capture photo sync cursor (ATTPHOTO PhotoStamp).
     * @param  int  $errorDelay  Delay in seconds before the device retries after a network error.
     * @param  int  $delay  Idle heartbeat polling interval in seconds for /iclock/getrequest.
     * @param  string  $transTimes  Scheduled daily upload time points formatted as 'HH:MM;HH:MM'.
     * @param  int  $transInterval  Interval in minutes between batch log transfers.
     * @param  string  $transFlag  10-position binary mask configuring automatic table uploads.
     * @param  int  $realtime  Real-time upload flag (1 = upload punches immediately, 0 = batch).
     * @param  int  $encrypt  Transport encryption flag (0 = plaintext HTTP, 1 = encrypted).
     * @param  string|int  $timezone  Device timezone identifier or UTC offset.
     */
    public function __construct(
        public string $serialNumber,
        public int $stamp = 0,
        public int $opStamp = 0,
        public int $photoStamp = 0,
        public int $errorDelay = 60,
        public int $delay = 30,
        public string $transTimes = '00:00;14:05',
        public int $transInterval = 1,
        public string $transFlag = '1111111111',
        public int $realtime = 1,
        public int $encrypt = 0,
        public string|int $timezone = 'UTC',
    ) {}

    /**
     * Build the raw plaintext response required by ZKTeco edge devices.
     *
     * Produces a newline-delimited wire string conforming to the ADMS handshake specification.
     *
     * @return string Plaintext response body terminated by a trailing newline.
     */
    public function toWireString(): string
    {
        $wireTimezone = $this->resolveTimezoneOffset($this->timezone);

        $lines = [
            "GET OPTION FROM: {$this->serialNumber}",
            "Stamp={$this->stamp}",
            "OpStamp={$this->opStamp}",
            "PhotoStamp={$this->photoStamp}",
            "ErrorDelay={$this->errorDelay}",
            "Delay={$this->delay}",
            "TransTimes={$this->transTimes}",
            "TransInterval={$this->transInterval}",
            "TransFlag={$this->transFlag}",
            "Realtime={$this->realtime}",
            "Encrypt={$this->encrypt}",
            "TimeZone={$wireTimezone}",
        ];

        return implode("\n", $lines)."\n";
    }

    /**
     * Resolve timezone into a hardware wire offset representation.
     *
     * ZKTeco hardware firmware expects a numeric UTC hour offset (e.g. 6 or -4)
     * rather than an IANA timezone identifier (e.g. 'Asia/Dhaka').
     *
     * @param  string|int  $timezone  IANA timezone string (e.g. 'Asia/Dhaka') or numeric hour offset (e.g. 6, '+6', -5).
     * @return string Normalized wire offset representation.
     */
    protected function resolveTimezoneOffset(string|int $timezone): string
    {
        if (is_numeric($timezone)) {
            return (string) (int) $timezone;
        }

        try {
            $tz = new \DateTimeZone((string) $timezone);
            $dt = new \DateTime('now', $tz);
            $offsetSeconds = $tz->getOffset($dt);

            return (string) (int) round($offsetSeconds / 3600);
        } catch (\Throwable) {
            return '0';
        }
    }
}

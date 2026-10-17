<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Events;

use DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a new attendance punch is successfully ingested from a device's ATTLOG push.
 *
 * Triggered during the processing of `/iclock/cdata?table=ATTLOG` when an incoming punch record
 * passes validation, RTC drift inspection, and database deduplication.
 *
 * Application listeners can subscribe to this event to trigger payroll calculations,
 * refresh real-time monitoring dashboards, or send automated late arrival and absence notifications.
 *
 * Example:
 * ```php
 * Event::listen(AttendanceLogged::class, function (AttendanceLogged $event) {
 *     // Process punch for payroll or notification
 *     Log::info("Punch recorded: user {$event->attendanceLog->pin} on {$event->device->serial_number}");
 * });
 * ```
 */
class AttendanceLogged
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  ZkAttendanceLog  $attendanceLog  The persisted attendance log record.
     * @param  ZkDevice  $device  The ZKTeco biometric terminal that pushed the attendance punch.
     */
    public function __construct(
        public ZkAttendanceLog $attendanceLog,
        public ZkDevice $device,
    ) {}
}

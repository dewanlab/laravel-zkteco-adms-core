<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Events;

use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUser;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event dispatched when a user record is created or updated from a device's OPERLOG push.
 *
 * Triggered when a ZKTeco terminal uploads operational audit records (`/iclock/cdata?table=OPERLOG`)
 * reflecting local user additions, edits, or deletions made directly at the device keypad or touchscreen.
 *
 * This event serves as the central server's defense against "ghost enrollments" — unauthorized local registrations
 * executed on a biometric terminal outside centralized HR or access management systems.
 * Listeners can verify the user's validity, alert security administrators, or queue automated purge commands.
 *
 * Example:
 * ```php
 * Event::listen(UserSynchronized::class, function (UserSynchronized $event) {
 *     Log::warning("User synced from terminal OPERLOG: PIN {$event->user->pin} on {$event->device->serial_number}");
 * });
 * ```
 */
class UserSynchronized
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  ZkUser  $user  The user model created or updated from the operation log.
     * @param  ZkDevice  $device  The terminal where the operation log was recorded.
     */
    public function __construct(
        public ZkUser $user,
        public ZkDevice $device,
    ) {}
}

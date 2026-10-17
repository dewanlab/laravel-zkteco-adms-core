<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Enums;

/**
 * ZKTeco attendance status codes.
 *
 * In the ZKTeco ADMS Push Protocol, every attendance event pushed via the
 * `/iclock/cdata` endpoint from the terminal's `ATTLOG` table contains a
 * `STATUS` integer field representing the user's punch state.
 *
 * Example:
 * ```php
 * $state = PunchState::fromValue($fields[2]);
 * echo $state->label(); // "Check In"
 * ```
 */
enum PunchState: int
{
    /**
     * Check In (Hardware Code: 0).
     * Standard shift commencement or clock-in punch.
     */
    case CheckIn = 0;

    /**
     * Check Out (Hardware Code: 1).
     * Standard shift conclusion or clock-out punch.
     */
    case CheckOut = 1;

    /**
     * Break Out (Hardware Code: 2).
     * Departure for a scheduled break or lunch interval.
     */
    case BreakOut = 2;

    /**
     * Break In (Hardware Code: 3).
     * Return from a scheduled break or lunch interval.
     */
    case BreakIn = 3;

    /**
     * Overtime In (Hardware Code: 4).
     * Clocking in for an authorized overtime work period.
     */
    case OvertimeIn = 4;

    /**
     * Overtime Out (Hardware Code: 5).
     * Clocking out upon completing an overtime work period.
     */
    case OvertimeOut = 5;

    /**
     * Meal Start (Hardware Code: 8).
     * Punch indicating the start of a meal period.
     */
    case MealStart = 8;

    /**
     * Other / Unrecognized (Fallback Code: 99).
     * Non-standard or custom state code from the device.
     */
    case Other = 99;

    /**
     * Resolve a PunchState instance from an integer, numeric string, or null.
     *
     * @param  int|string|null  $value  Raw STATUS field value from the device ATTLOG line.
     * @return self Resolved PunchState enum instance.
     */
    public static function fromValue(int|string|null $value): self
    {
        if ($value === null) {
            return self::CheckIn;
        }

        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return self::Other;
        }

        return self::tryFrom($intVal) ?? self::Other;
    }

    /**
     * Get the human-readable display label for the punch state.
     *
     * @return string Human-readable label (e.g. "Check In", "Break Out").
     */
    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Check In',
            self::CheckOut => 'Check Out',
            self::BreakOut => 'Break Out',
            self::BreakIn => 'Break In',
            self::OvertimeIn => 'Overtime In',
            self::OvertimeOut => 'Overtime Out',
            self::MealStart => 'Meal Start',
            self::Other => 'Other',
        };
    }
}

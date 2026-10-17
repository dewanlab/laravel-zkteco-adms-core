<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Enums;

/**
 * ZKTeco attendance verification modes.
 *
 * In the ZKTeco ADMS Push Protocol, each attendance record in the `ATTLOG` table
 * contains a `VERIFY` integer field indicating the authentication modality
 * employed by the user at the terminal.
 *
 * Example:
 * ```php
 * $mode = VerifyMode::fromValue($fields[3]);
 * echo $mode->label(); // "Fingerprint"
 * ```
 */
enum VerifyMode: int
{
    /**
     * Password / PIN (Hardware Code: 0).
     * User authenticated using numeric PIN/keypad password.
     */
    case Password = 0;

    /**
     * Fingerprint (Hardware Code: 1).
     * Optical or capacitive fingerprint biometric verification.
     */
    case Fingerprint = 1;

    /**
     * RFID Card (Hardware Code: 2).
     * Proximity RFID badge, MIFARE, or EM-Marine card tap.
     */
    case Card = 2;

    /**
     * Password + Fingerprint (Hardware Code: 3).
     * Multi-factor verification requiring PIN password followed by fingerprint.
     */
    case PasswordAndFingerprint = 3;

    /**
     * Fingerprint + Card (Hardware Code: 4).
     * Multi-factor verification requiring card badge scan followed by fingerprint.
     */
    case FingerprintAndCard = 4;

    /**
     * Visible Light Face (Hardware Code: 15).
     * Facial recognition using Visible Light binocular camera sensor.
     */
    case VisibleLightFace = 15;

    /**
     * Palm Print (Hardware Code: 25).
     * Near-infrared palm print / subcutaneous palm vein scan.
     */
    case Palm = 25;

    /**
     * Other / Unrecognized (Fallback Code: 99).
     * Non-standard or vendor-specific verification mode.
     */
    case Other = 99;

    /**
     * Resolve a VerifyMode instance from an integer, numeric string, or null.
     *
     * @param  int|string|null  $value  Raw VERIFY field value from the device ATTLOG line.
     * @return self Resolved VerifyMode enum instance.
     */
    public static function fromValue(int|string|null $value): self
    {
        if ($value === null) {
            return self::Password;
        }

        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return self::Other;
        }

        return self::tryFrom($intVal) ?? self::Other;
    }

    /**
     * Get the human-readable display label for the verification mode.
     *
     * @return string Human-readable label (e.g. "Fingerprint", "Visible Light Face").
     */
    public function label(): string
    {
        return match ($this) {
            self::Password => 'Password',
            self::Fingerprint => 'Fingerprint',
            self::Card => 'RFID Card',
            self::PasswordAndFingerprint => 'Password + Fingerprint',
            self::FingerprintAndCard => 'Fingerprint + Card',
            self::VisibleLightFace => 'Visible Light Face',
            self::Palm => 'Palm Print',
            self::Other => 'Other',
        };
    }
}

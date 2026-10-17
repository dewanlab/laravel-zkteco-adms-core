<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Enums;

/**
 * Biometric modality types on ZKTeco terminals.
 *
 * In the ZKTeco ADMS Push Protocol, this enum maps directly to the `Type`
 * parameter in the terminal's `biodata` table (e.g. pushed via `/iclock/cdata?table=biodata`
 * or provisioned via `DATA UPDATE biodata`).
 *
 * Example:
 * ```php
 * $type = BioType::fromValue(9);
 * echo $type->label(); // "Visible Light Face Mesh"
 * ```
 */
enum BioType: int
{
    /**
     * Standard Fingerprint Minutiae (Hardware Code: 1).
     * Minutiae-based fingerprint template (typically ZKFinger 10.0 format).
     */
    case Fingerprint = 1;

    /**
     * Visible Light Facial Mesh (Hardware Code: 9).
     * Deep-learning facial mesh template captured by dual/binocular camera sensors.
     */
    case VisibleLightFace = 9;

    /**
     * Palm Print (Hardware Code: 25).
     * Palm print biometric template with subcutaneous vascular/vein mapping.
     */
    case Palm = 25;

    /**
     * Finger Vein (Hardware Code: 26).
     * Near-infrared finger vascular pattern biometric template.
     */
    case FingerVein = 26;

    /**
     * Unknown Biometric Type (Fallback Code: 99).
     * Unrecognized or proprietary biometric template modality.
     */
    case Unknown = 99;

    /**
     * Resolve a BioType instance from an integer, numeric string, or null.
     *
     * @param  int|string|null  $value  Raw Type parameter value from the biodata table.
     * @return self Resolved BioType enum instance.
     */
    public static function fromValue(int|string|null $value): self
    {
        if ($value === null) {
            return self::Unknown;
        }

        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false) {
            return self::Unknown;
        }

        return self::tryFrom($intVal) ?? self::Unknown;
    }

    /**
     * Get the human-readable display label for the biometric type.
     *
     * @return string Human-readable label (e.g. "Fingerprint Template", "Visible Light Face Mesh").
     */
    public function label(): string
    {
        return match ($this) {
            self::Fingerprint => 'Fingerprint Template',
            self::VisibleLightFace => 'Visible Light Face Mesh',
            self::Palm => 'Palm Print Template',
            self::FingerVein => 'Finger Vein Template',
            self::Unknown => 'Unknown Biometric',
        };
    }
}

<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\DTOs;

use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;

/**
 * Represents a single biometric template from the ZKTeco `biodata` device table.
 *
 * In modern ZKTeco ADMS Push Protocol firmware (ADMS 2.0+), unified biometric
 * templates (fingerprint minutiae, visible light face meshes, and palm prints)
 * are managed via the `biodata` table rather than legacy fragmented tables
 * (such as `templatev10` or `biophoto`).
 *
 * This DTO is used both for parsing incoming device uploads (`/iclock/cdata?table=biodata`)
 * and for constructing outbound `DATA UPDATE biodata` synchronization commands.
 *
 * Example:
 * ```php
 * $payload = new BiodataPayload(
 *     pin: '1001',
 *     bioType: BioType::Fingerprint,
 *     index: 0,
 *     template: 'Rk1SAC4xAAAB...'
 * );
 * $params = $payload->toCommandParams();
 * ```
 */
final readonly class BiodataPayload
{
    /**
     * Create a new BiodataPayload instance.
     *
     * @param  string  $pin  User enrollment identifier (PIN) associated with the template.
     * @param  BioType  $bioType  Biometric modality type (Fingerprint, Face, Palm, etc.).
     * @param  int  $index  Biometric index (e.g. finger position 0-9, face index 0).
     * @param  string  $template  Base64-encoded or raw biometric feature template data.
     * @param  int  $size  Byte size of the template (0 to use string length).
     * @param  bool  $isValid  Whether the template is active and valid for verification.
     * @param  int  $no  Template sequence number (defaults to 0).
     * @param  int  $duress  Duress alarm flag (1 = silent alarm triggered upon verification, 0 = normal).
     */
    public function __construct(
        public string $pin,
        public BioType $bioType,
        public int $index,
        public string $template,
        public int $size = 0,
        public bool $isValid = true,
        public int $no = 0,
        public int $duress = 0,
    ) {}

    /**
     * Generate the tab-delimited command parameter string for DATA UPDATE biodata.
     *
     * Produces parameters formatted as:
     * `Pin=<pin>\tNo=<no>\tIndex=<index>\tValid=<1/0>\tDuress=<duress>\tType=<type>\tMajorVer=10\tMinorVer=0\tTmp=<template>`
     *
     * @return string Tab-delimited command parameter string suitable for queuing to the device.
     */
    public function toCommandParams(): string
    {
        return sprintf(
            "Pin=%s\tNo=%d\tIndex=%d\tValid=%d\tDuress=%d\tType=%d\tMajorVer=10\tMinorVer=0\tTmp=%s",
            $this->pin,
            $this->no,
            $this->index,
            $this->isValid ? 1 : 0,
            $this->duress,
            $this->bioType->value,
            $this->template
        );
    }

    /**
     * Convert the biometric payload to an associative array.
     *
     * @return array<string, mixed> Associative array representation of the biodata payload.
     */
    public function toArray(): array
    {
        return [
            'pin' => $this->pin,
            'bio_type' => $this->bioType->value,
            'bio_type_label' => $this->bioType->label(),
            'index' => $this->index,
            'size' => $this->size > 0 ? $this->size : strlen($this->template),
            'is_valid' => $this->isValid,
            'no' => $this->no,
            'duress' => $this->duress,
            'template' => $this->template,
        ];
    }
}

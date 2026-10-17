<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Services\Parsers;

use DewanLab\LaravelZktecoAdmsCore\DTOs\BiodataPayload;
use DewanLab\LaravelZktecoAdmsCore\Enums\BioType;

/**
 * Parses biometric template data pushed by ZKTeco devices to /iclock/cdata?table=BIODATA.
 *
 * In the ZKTeco ADMS Push Protocol, biometric templates (fingerprints, facial recognition,
 * palm prints, etc.) are synchronized as tab-delimited key=value pairs containing template
 * metadata and a base64-encoded proprietary biometric feature vector.
 *
 * Wire format per line:
 * `Pin=1001\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=9\tTmp=BASE64DATA...`
 *
 * Key fields:
 * - `Pin`: Terminal user identifier / employee ID.
 * - `No`: Biometric index or finger/sub-item position (0-9).
 * - `Index`: Internal slot or database index on the terminal.
 * - `Valid`: Active flag ('1' = active/valid, '0' = inactive).
 * - `Duress`: Duress flag (1 = emergency duress template, 0 = normal).
 * - `Type`: Biometric modality code corresponding to {@see BioType} (e.g. 1=Fingerprint, 9=Face).
 * - `Size`: Byte size of the template data.
 * - `Tmp`: Base64-encoded biometric template string.
 *
 * Example:
 * ```php
 * $parser = new BiodataParser();
 * $payload = $parser->parseLine("Pin=1001\tNo=0\tIndex=0\tValid=1\tDuress=0\tType=9\tTmp=BASE64...");
 * ```
 */
class BiodataParser
{
    /**
     * Parse a single tab-delimited BIODATA push line into a BiodataPayload DTO.
     *
     * Extracts case-insensitive key=value tokens separated by tabs. Requires 'pin',
     * 'type', and 'tmp' tokens; returns null if any required token is missing or if
     * the line is empty.
     *
     * @param  string  $line  Raw tab-delimited line representing a biometric template.
     * @return BiodataPayload|null The parsed biometric DTO, or null if line is invalid or missing required tokens.
     */
    public function parseLine(string $line): ?BiodataPayload
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        $fields = [];
        $tokens = explode("\t", $trimmed);

        foreach ($tokens as $token) {
            $pos = strpos($token, '=');
            if ($pos !== false) {
                $key = strtolower(trim(substr($token, 0, $pos)));
                $val = trim(substr($token, $pos + 1));
                $fields[$key] = $val;
            }
        }

        if (! isset($fields['pin'], $fields['type'], $fields['tmp'])) {
            return null;
        }

        return new BiodataPayload(
            pin: (string) $fields['pin'],
            bioType: BioType::fromValue($fields['type']),
            index: (int) ($fields['index'] ?? 0),
            template: (string) $fields['tmp'],
            size: (int) ($fields['size'] ?? strlen((string) $fields['tmp'])),
            isValid: ($fields['valid'] ?? '1') === '1',
            no: (int) ($fields['no'] ?? 0),
            duress: (int) ($fields['duress'] ?? 0),
        );
    }
}

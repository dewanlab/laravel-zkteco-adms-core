<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Services\Parsers;

/**
 * Parses OPERLOG payloads pushed to /iclock/cdata?table=OPERLOG.
 *
 * In the ZKTeco ADMS Push Protocol, administrative actions performed directly on the
 * terminal's physical UI (such as user additions, card enrollments, and template modifications)
 * trigger operation log records pushed to the server.
 *
 * Payload format:
 * Tab-delimited `key=value` pairs per line, representing the operation attributes.
 * `PIN=1001\tName=John\tPri=0\tPasswd=\tCard=12345678\tGrp=1\tTZ=1`
 *
 * Common fields:
 * - `PIN`: Terminal user identifier / employee ID.
 * - `Name`: User display name.
 * - `Pri`: Privilege level (0 = Standard User, 14 = Device Administrator).
 * - `Passwd`: Access keypad password.
 * - `Card`: Enrolled RF card number.
 * - `Grp`: Access control group assignment.
 * - `TZ`: Associated access timezone index.
 *
 * Example:
 * ```php
 * $parser = new OperationLogParser();
 * $records = $parser->parse("PIN=1001\tName=John\tPri=0\tCard=12345678");
 * ```
 */
class OperationLogParser
{
    /**
     * Parse a raw OPERLOG payload into structured associative key-value records.
     *
     * Splits lines across CRLF/LF, tokenizes by tab, and extracts key-value pairs
     * while discarding blank or malformed lines.
     *
     * @param  string  $payload  Raw plain text payload containing tab-delimited key=value pairs per line.
     * @return array<int, array<string, string>> Array of parsed records, each as an associative key => value map.
     */
    public function parse(string $payload): array
    {
        $records = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($payload));

        if ($lines === false || $lines === []) {
            return [];
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed === '0') {
                continue;
            }

            $fields = [];
            $tokens = explode("\t", $trimmed);

            foreach ($tokens as $token) {
                $token = trim($token);
                $pos = strpos($token, '=');
                if ($pos !== false) {
                    $key = trim(substr($token, 0, $pos));
                    $val = trim(substr($token, $pos + 1));

                    // Normalize prefix tags like "USER PIN" -> "PIN", "FP PIN" -> "PIN"
                    if (str_contains($key, ' ')) {
                        $parts = explode(' ', $key);
                        $key = end($parts);
                    }

                    $fields[$key] = $val;
                }
            }

            if ($fields !== []) {
                $records[] = $fields;
            }
        }

        return $records;
    }
}

<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Services\Parsers;

use DewanLab\LaravelZktecoAdmsCore\DTOs\CommandAck;

/**
 * Parses asynchronous command execution results POSTed by devices to /iclock/devicecmd.
 *
 * In the ZKTeco ADMS Push Protocol, after a device executes commands delivered during
 * polling (/iclock/getrequest), it asynchronously posts the execution results back to
 * /iclock/devicecmd.
 *
 * Format:
 * URL-encoded key-value pairs per line:
 * `ID=<CmdID>&Return=<Code>&CMD=<Name>`
 *
 * Features & resilience handling:
 * - Batch Processing: Supports multi-line batched acknowledgments where multiple commands are acknowledged at once.
 * - Multi-platform Line Breaks: Handles Windows (\r\n), legacy Mac (\r), and Unix (\n) line breaks.
 * - Strict Validation: Validates that ID and Return parameters are valid integers, ignoring unparseable or corrupted lines.
 *
 * Example:
 * ```php
 * $parser = new CommandAckParser();
 * $acks = $parser->parse("ID=295&Return=0&CMD=DATA UPDATE\nID=296&Return=-1002&CMD=USER ADD");
 * ```
 */
class CommandAckParser
{
    /**
     * Parse raw /iclock/devicecmd request payload into structured CommandAck DTOs.
     *
     * Supports both single-command acknowledgments and batched multiline acknowledgments.
     *
     * @param  string  $payload  Raw HTTP request body containing URL-encoded key-value pairs per line.
     * @return array<int, CommandAck> List of successfully parsed command acknowledgments.
     */
    public function parse(string $payload): array
    {
        $acks = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($payload));

        if ($lines === false || $lines === []) {
            return [];
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            parse_str($trimmed, $parsed);

            $id = filter_var($parsed['ID'] ?? null, FILTER_VALIDATE_INT);
            $ret = filter_var($parsed['Return'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false || $ret === false) {
                continue;
            }

            $rawCmd = $parsed['CMD'] ?? '';
            $cmdName = is_string($rawCmd) ? $rawCmd : '';

            $acks[] = new CommandAck(
                commandId: $id,
                returnCode: $ret,
                command: $cmdName,
                raw: $trimmed
            );
        }

        return $acks;
    }
}

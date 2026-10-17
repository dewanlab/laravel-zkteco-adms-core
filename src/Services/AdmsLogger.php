<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Services;

use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Dedicated logger service for ZKTeco ADMS communications.
 *
 * Provides structured, clean logging for:
 * - Device heartbeat & polling (`/iclock/getrequest`, `/iclock/cdata`)
 * - Inbound payloads (`ATTLOG`, `OPERLOG`, `BIODATA`, `USERINFO`)
 * - Push operations (user creation/updates sent to device)
 * - Pull operations (queries and log watermark resets)
 * - Command deliveries and device execution acknowledgments
 */
class AdmsLogger
{
    /**
     * Get the configured logger channel or default driver.
     */
    public static function channel(): ?LoggerInterface
    {
        if (! config('zkteco-adms-core.logging.enabled', true)) {
            return null;
        }

        $channelName = (string) config('zkteco-adms-core.logging.channel', 'zkteco-adms');

        try {
            return Log::channel($channelName);
        } catch (\Throwable) {
            return Log::driver();
        }
    }

    /**
     * Log an incoming device request.
     */
    public static function incoming(Request $request): void
    {
        $logger = self::channel();
        if ($logger === null) {
            return;
        }

        $sn = (string) ($request->query('SN') ?? $request->query('sn') ?? 'UNKNOWN');
        $endpoint = $request->path();
        $method = $request->method();
        $table = (string) ($request->query('table') ?? $request->query('tablename') ?? '');

        $context = [
            'sn' => $sn,
            'method' => $method,
            'endpoint' => $endpoint,
            'ip' => $request->ip(),
        ];

        if ($table !== '') {
            $context['table'] = $table;
        }

        $query = $request->query();
        unset($query['SN'], $query['sn']);
        if (! empty($query)) {
            $context['query'] = $query;
        }

        $body = (string) $request->getContent();
        if ($body !== '') {
            $context['body_length'] = strlen($body);
            // Include summary/snippet of body for quick inspection
            $lines = array_filter(explode("\n", trim($body)));
            $context['lines_count'] = count($lines);
            if (count($lines) <= 5) {
                $context['payload'] = $lines;
            } else {
                $context['sample'] = array_slice($lines, 0, 3);
            }
        }

        $logger->info(sprintf('[ZKTeco ADMS] [%s] %s /%s%s', $sn, $method, $endpoint, $table ? " ({$table})" : ''), $context);
    }

    /**
     * Log command polling and delivery to a device.
     */
    public static function commandDelivery(ZkDevice $device, string $wirePayload): void
    {
        $logger = self::channel();
        if ($logger === null) {
            return;
        }

        $lines = array_filter(explode("\n", trim($wirePayload)));

        $logger->info(sprintf('[ZKTeco ADMS] [%s] -> Sent %d Pending Command(s)', $device->serial_number, count($lines)), [
            'sn' => $device->serial_number,
            'commands' => $lines,
        ]);
    }

    /**
     * Log command execution callback acknowledgment from device.
     */
    public static function commandAck(ZkDevice $device, int $cmdId, string $command, int $returnCode, ?string $error = null): void
    {
        $logger = self::channel();
        if ($logger === null) {
            return;
        }

        if ($returnCode === 0) {
            $logger->info(sprintf('[ZKTeco ADMS] [%s] <- Command #%d Acknowledged (SUCCESS)', $device->serial_number, $cmdId), [
                'sn' => $device->serial_number,
                'cmd_id' => $cmdId,
                'command' => $command,
                'return_code' => 0,
            ]);
        } else {
            $logger->error(sprintf('[ZKTeco ADMS] [%s] <- Command #%d FAILED (Code %d)', $device->serial_number, $cmdId, $returnCode), [
                'sn' => $device->serial_number,
                'cmd_id' => $cmdId,
                'command' => $command,
                'return_code' => $returnCode,
                'error' => $error,
            ]);
        }
    }

    /**
     * Log data ingestion results (attendance or user records saved).
     *
     * @param  ZkDevice  $device  Hardware device instance.
     * @param  string  $type  Type of record (e.g. ATTLOG, USERINFO).
     * @param  int  $count  Number of records processed.
     * @param  array<string, mixed>  $context  Additional context metadata.
     */
    public static function ingested(ZkDevice $device, string $type, int $count, array $context = []): void
    {
        $logger = self::channel();
        if ($logger === null) {
            return;
        }

        $logger->info(sprintf('[ZKTeco ADMS] [%s] Processed %d %s record(s)', $device->serial_number, $count, $type), array_merge([
            'sn' => $device->serial_number,
            'type' => $type,
            'count' => $count,
        ], $context));
    }
}

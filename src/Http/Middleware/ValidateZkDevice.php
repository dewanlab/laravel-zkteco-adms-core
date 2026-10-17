<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Http\Middleware;

use Closure;
use DewanLab\LaravelZktecoAdmsCore\Enums\DeviceStatus;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Services\AdmsLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that validates the SN (serial number) query parameter on all ADMS protocol requests.
 *
 * In the ZKTeco ADMS Push Protocol, every HTTP request initiated by a hardware terminal includes
 * its unique hardware serial number in the query string (`?SN=...`).
 *
 * Responsibilities:
 * - Validation: Enforces the presence of the `SN` query parameter, responding with HTTP 400 if missing.
 * - Auto-Registration: Automatically provisions newly discovered terminals in the database,
 *   populating hardware attributes from query parameters (`DeviceType`, `pushver`, `FirmwareVersion`).
 * - Presence Tracking: Updates the device's `last_activity_at` timestamp and IP address via `markAsOnline()`.
 * - Request Injection: Injects the resolved {@see ZkDevice} Eloquent model into request attributes (`zk_device`)
 *   for consumption by downstream controllers.
 *
 * Example route usage:
 * ```php
 * Route::middleware(ValidateZkDevice::class)->group(function () {
 *     Route::match(['GET', 'POST'], '/iclock/cdata', [AdmsController::class, 'handleCdata']);
 * });
 * ```
 */
class ValidateZkDevice
{
    /**
     * Handle incoming ADMS protocol request: validate SN, update or register device, and inject into request.
     *
     * @param  Request  $request  Incoming HTTP request from the hardware terminal.
     * @param  Closure(Request): Response  $next  Next request handler.
     * @return Response Downstream HTTP response or HTTP 400 if SN is missing.
     */
    public function handle(Request $request, Closure $next): Response
    {
        AdmsLogger::incoming($request);

        $sn = (string) (
            $request->query('SN')
            ?? $request->query('sn')
            ?? $request->query('Sn')
            ?? $request->input('SN')
            ?? $request->input('sn')
            ?? ''
        );

        if ($sn === '') {
            Log::warning('ADMS Request missing SN parameter', [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'headers' => $request->headers->all(),
                'query' => $request->query(),
                'body' => $request->getContent(),
            ]);

            return response("Missing SN parameter\n", 400);
        }

        $deviceType = (string) ($request->query('DeviceType') ?? $request->query('devicetype') ?? '');
        $pushVer = (string) ($request->query('pushver') ?? $request->query('PUSHVER') ?? '');
        $firmwareVersion = (string) ($request->query('FirmwareVersion') ?? $request->query('firmwareversion') ?? '');

        $device = ZkDevice::firstOrCreate(
            ['serial_number' => $sn],
            [
                'name' => "Device {$sn}",
                'ip_address' => $request->ip(),
                'status' => DeviceStatus::Online,
                'timezone' => (string) config('zkteco-adms-core.handshake.timezone', 'UTC'),
                'device_type' => $deviceType ?: null,
                'push_version' => $pushVer ?: null,
                'firmware_version' => $firmwareVersion ?: null,
            ]
        );

        $device->markAsOnline($request->ip());

        $request->attributes->set('zk_device', $device);

        return $next($request);
    }
}

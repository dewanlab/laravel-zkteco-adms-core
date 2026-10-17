<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Enums;

/**
 * Connectivity state of a ZKTeco edge terminal.
 *
 * In the ZKTeco ADMS Push Protocol, edge terminals communicate with the central
 * server by periodically sending HTTP requests (handshake via `/iclock/cdata`,
 * command polling via `/iclock/getrequest`, or attendance push). Because devices
 * reside behind firewalls or NAT, server-side status tracking relies on a heartbeat
 * timeout mechanism based on the configured polling interval (`Delay`).
 *
 * Lifecycle states:
 * - `Online`: The terminal has communicated within the expected heartbeat window.
 * - `Stale`: The terminal has missed expected heartbeats but has not exceeded the full offline threshold.
 * - `Offline`: The terminal has exceeded the maximum tolerance threshold and is deemed disconnected.
 *
 * Example:
 * ```php
 * if ($device->status === DeviceStatus::Online) {
 *     // Device is active and communicating.
 * }
 * ```
 */
enum DeviceStatus: string
{
    /**
     * The device is actively communicating within the expected heartbeat interval.
     */
    case Online = 'online';

    /**
     * The device has stopped communicating and exceeded the offline threshold.
     */
    case Offline = 'offline';

    /**
     * The device has missed a scheduled poll interval but has not reached the offline threshold.
     */
    case Stale = 'stale';
}

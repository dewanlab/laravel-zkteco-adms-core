<?php

declare(strict_types=1);

namespace DewanLab\LaravelZktecoAdmsCore\Http\Controllers;

use DewanLab\LaravelZktecoAdmsCore\DTOs\HandshakeConfig;
use DewanLab\LaravelZktecoAdmsCore\Events\AttendanceLogged;
use DewanLab\LaravelZktecoAdmsCore\Events\CommandAcknowledged;
use DewanLab\LaravelZktecoAdmsCore\Events\CommandFailed;
use DewanLab\LaravelZktecoAdmsCore\Events\UserSynchronized;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkAttendanceLog;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkBiodata;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice;
use DewanLab\LaravelZktecoAdmsCore\Models\ZkUser;
use DewanLab\LaravelZktecoAdmsCore\Services\AdmsLogger;
use DewanLab\LaravelZktecoAdmsCore\Services\Commands\CommandQueueManager;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\AttendanceParser;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\BiodataParser;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\CommandAckParser;
use DewanLab\LaravelZktecoAdmsCore\Services\Parsers\OperationLogParser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Primary HTTP controller implementing all ZKTeco ADMS Push Protocol endpoints.
 *
 * In the ZKTeco Automatic Data Master Server (ADMS) Push Protocol, hardware biometric
 * terminals communicate with the backend server entirely over standard HTTP/HTTPS requests.
 * This controller orchestrates the complete terminal communication lifecycle:
 *
 * 1. Handshake & Registration:
 *    - `GET /iclock/cdata` or `GET /iclock/registry`: Terminal requests connection options;
 *      server responds with `GET OPTION FROM: <SN>` and configuration parameters.
 * 2. Data Ingestion:
 *    - `POST /iclock/cdata?table=ATTLOG`: Terminal pushes clock-in/out attendance logs.
 *    - `POST /iclock/cdata?table=OPERLOG`: Terminal pushes administrative audit logs (user changes, enrollments).
 *    - `POST /iclock/cdata?table=BIODATA`: Terminal pushes biometric templates (face, fingerprint, palm).
 * 3. Command Delivery (Polling):
 *    - `GET /iclock/getrequest`: Terminal polls for server-queued commands.
 * 4. Asynchronous Execution Acknowledgment:
 *    - `POST /iclock/devicecmd`: Terminal posts command execution return codes.
 * 5. Time Synchronization:
 *    - `GET /iclock/rtdata`: Terminal retrieves server timestamp.
 */
class AdmsController
{
    /**
     * Create a new ADMS controller instance.
     *
     * @param  AttendanceParser  $attendanceParser  Parser for raw ATTLOG attendance punch records.
     * @param  OperationLogParser  $operationLogParser  Parser for raw OPERLOG terminal operation records.
     * @param  CommandAckParser  $commandAckParser  Parser for /iclock/devicecmd execution callbacks.
     * @param  BiodataParser  $biodataParser  Parser for raw BIODATA biometric template payloads.
     * @param  CommandQueueManager  $commandQueueManager  Manager for queuing and delivering device commands.
     */
    public function __construct(
        protected AttendanceParser $attendanceParser,
        protected OperationLogParser $operationLogParser,
        protected CommandAckParser $commandAckParser,
        protected BiodataParser $biodataParser,
        protected CommandQueueManager $commandQueueManager,
    ) {}

    /**
     * Handshake and primary data ingestion endpoint: /iclock/cdata.
     *
     * If invoked via GET or without a `table` query parameter, serves the initial handshake
     * configuration (`GET OPTION FROM: <SN>`). If invoked via POST with a specific `table`,
     * delegates ingestion to the corresponding handler (ATTLOG, OPERLOG, or BIODATA).
     *
     * @param  Request  $request  Incoming HTTP request containing validated device attribute.
     * @return Response HTTP response conforming to ZKTeco push wire protocol expectations.
     */
    public function handleCdata(Request $request): Response
    {
        /** @var ZkDevice $device */
        $device = $request->attributes->get('zk_device');
        $table = strtoupper((string) $request->query('table', ''));

        // GET request or initial handshake without table -> return GET OPTION FROM: <SN>
        if ($request->isMethod('GET') || $table === '') {
            return $this->renderHandshakeResponse($device);
        }

        return match ($table) {
            'ATTLOG' => $this->handleAttLog($request, $device),
            'OPERLOG' => $this->handleOperLog($request, $device),
            'BIODATA' => $this->handleBiodata($request, $device),
            'USERINFO', 'USER' => $this->handleUserInfo($request, $device),
            default => response("OK\n", 200),
        };
    }

    /**
     * Query data ingestion endpoint: /iclock/querydata.
     *
     * Hardware terminals respond to `DATA QUERY <TABLE>` commands by pushing matching
     * record sets back to this endpoint. Supports USERINFO and ATTLOG queries.
     *
     * @param  Request  $request  Incoming HTTP request containing tabular query response.
     * @return Response Plain text response acknowledging receipt ("OK\n").
     */
    public function handleQueryData(Request $request): Response
    {
        /** @var ZkDevice $device */
        $device = $request->attributes->get('zk_device');
        $table = strtoupper((string) ($request->query('tablename') ?: $request->query('table') ?: 'USERINFO'));

        return match ($table) {
            'USERINFO', 'USER' => $this->handleUserInfo($request, $device),
            'ATTLOG' => $this->handleAttLog($request, $device),
            default => response("OK\n", 200),
        };
    }

    /**
     * Heartbeat and pending commands polling: /iclock/getrequest.
     *
     * Terminals regularly poll this endpoint to maintain online heartbeat status and
     * retrieve pending server-queued commands in wire format (`C:<CmdID>:<Command>\n`).
     *
     * @param  Request  $request  Incoming HTTP request containing the validated device.
     * @return Response Text plain response containing queued wire commands or "OK\n".
     */
    public function handleGetRequest(Request $request): Response
    {
        /** @var ZkDevice $device */
        $device = $request->attributes->get('zk_device');

        $wirePayload = $this->commandQueueManager->drainToWire($device);

        if ($wirePayload !== "OK\n") {
            AdmsLogger::commandDelivery($device, $wirePayload);
        }

        return response($wirePayload, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Asynchronous command acknowledgment callback: /iclock/devicecmd.
     *
     * Devices post command execution results (return codes and error messages) back to this
     * endpoint. The method updates command records and fires {@see CommandAcknowledged}
     * or {@see CommandFailed} events.
     *
     * @param  Request  $request  Incoming HTTP request containing URL-encoded command result body.
     * @return Response Plain text response acknowledging receipt ("OK\n").
     */
    public function handleDeviceCmd(Request $request): Response
    {
        /** @var ZkDevice $device */
        $device = $request->attributes->get('zk_device');
        $body = (string) $request->getContent();
        $acks = $this->commandAckParser->parse($body);

        foreach ($acks as $ack) {
            $cmd = $this->commandQueueManager->acknowledge($ack);
            if ($cmd !== null) {
                AdmsLogger::commandAck($device, $cmd->id, $cmd->command_string, $ack->returnCode, $ack->errorMessage());

                if ($ack->isSuccess()) {
                    event(new CommandAcknowledged($cmd, $cmd->device, $ack->returnCode));
                } else {
                    event(new CommandFailed($cmd, $cmd->device, $ack->returnCode, $ack->errorMessage()));
                }
            }
        }

        return response("OK\n", 200);
    }

    /**
     * Device capability handshake: /iclock/registry.
     *
     * Terminals initiate registration during boot; server responds with handshake configuration.
     *
     * @param  Request  $request  Incoming HTTP request containing the validated device.
     * @return Response Handshake response containing device configuration parameters.
     */
    public function handleRegistry(Request $request): Response
    {
        /** @var ZkDevice $device */
        $device = $request->attributes->get('zk_device');

        return $this->renderHandshakeResponse($device);
    }

    /**
     * Realtime server timestamp endpoint: /iclock/rtdata.
     *
     * Allows hardware terminals to synchronize their internal Real-Time Clock (RTC)
     * with the server's current timestamp.
     *
     * @return Response Formatted server timestamp string ("Y-m-d H:i:s\n").
     */
    public function handleRtData(Request $request): Response
    {
        /** @var ZkDevice|null $device */
        $device = $request->attributes->get('zk_device');
        $tz = $device->timezone ?? config('zkteco-adms-core.handshake.timezone', 'UTC');

        try {
            $timestamp = now((string) $tz)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            $timestamp = now()->format('Y-m-d H:i:s');
        }

        return response($timestamp."\n", 200);
    }

    /**
     * Ingest and persist raw ATTLOG attendance punch records.
     *
     * Parses the tab-delimited punch payload, ensures idempotency against duplicate punches,
     * stores new records in the database, and dispatches {@see AttendanceLogged} events.
     *
     * @param  Request  $request  HTTP request containing the raw ATTLOG payload in the body.
     * @param  ZkDevice  $device  Sending hardware device instance.
     * @return Response Plain text response indicating ingested count (e.g. "OK: 5\n").
     */
    protected function handleAttLog(Request $request, ZkDevice $device): Response
    {
        $body = (string) $request->getContent();
        $punches = $this->attendanceParser->parse(
            $body,
            $device->serial_number,
            $device->timezone ?? 'UTC'
        );

        $insertedCount = 0;
        foreach ($punches as $punch) {
            // Idempotent upsert/create
            $exists = ZkAttendanceLog::where('device_id', $device->id)
                ->where('pin', $punch->pin)
                ->where('recorded_at', $punch->recordedAt)
                ->exists();

            if (! $exists) {
                $inserted = ZkAttendanceLog::create([
                    'device_id' => $device->id,
                    'pin' => $punch->pin,
                    'recorded_at' => $punch->recordedAt,
                    'occurred_at' => $punch->recordedAt,
                    'status' => $punch->state,
                    'verify_mode' => $punch->verifyMode,
                    'work_code' => $punch->workCode,
                    'raw_payload' => $punch->rawFields,
                ]);
                $insertedCount++;

                event(new AttendanceLogged($inserted, $device));
            }
        }

        if ($insertedCount > 0) {
            AdmsLogger::ingested($device, 'ATTLOG', $insertedCount);
        }

        return response("OK: {$insertedCount}\n", 200);
    }

    /**
     * Ingest and persist raw OPERLOG terminal operation audit records.
     *
     * Extracts administrative actions executed on the device UI (such as user modifications,
     * card numbers, and privilege escalations), upserts corresponding ZkUser models,
     * and dispatches {@see UserSynchronized} events.
     *
     * @param  Request  $request  HTTP request containing tab-delimited key=value operational logs.
     * @param  ZkDevice  $device  Sending hardware device instance.
     * @return Response Plain text acknowledgment response ("OK\n").
     */
    protected function handleOperLog(Request $request, ZkDevice $device): Response
    {
        $body = (string) $request->getContent();
        $records = $this->operationLogParser->parse($body);

        $userCount = 0;
        foreach ($records as $fields) {
            if (isset($fields['PIN'])) {
                $user = ZkUser::where('pin', $fields['PIN'])->first();
                $updateData = [];

                if (isset($fields['Name']) && $fields['Name'] !== '') {
                    $updateData['name'] = $fields['Name'];
                }
                if (isset($fields['Card']) && $fields['Card'] !== '') {
                    $updateData['card_number'] = $fields['Card'];
                }
                if (isset($fields['Passwd']) && $fields['Passwd'] !== '') {
                    $updateData['password'] = $fields['Passwd'];
                }
                if (isset($fields['Pri'])) {
                    $updateData['privilege'] = (int) $fields['Pri'];
                }
                if (isset($fields['Grp'])) {
                    $updateData['group_id'] = (int) $fields['Grp'];
                }
                if (isset($fields['TZ'])) {
                    $updateData['timezone_id'] = (int) $fields['TZ'];
                }

                if ($user !== null) {
                    if (! empty($updateData)) {
                        $user->update($updateData);
                    }
                } else {
                    $user = ZkUser::create(array_merge(['pin' => $fields['PIN']], $updateData));
                }

                $userCount++;
                event(new UserSynchronized($user, $device));
            }
        }

        if ($userCount > 0) {
            AdmsLogger::ingested($device, 'USERINFO (OPERLOG)', $userCount);
        }

        return response("OK\n", 200);
    }

    /**
     * Ingest and persist raw BIODATA biometric template streams.
     *
     * Iterates through pushed lines, parses biometric templates (face vectors, fingerprints, palms),
     * and updates or creates corresponding ZkBiodata records in the database.
     *
     * @param  Request  $request  HTTP request containing the tab-delimited biometric template payload.
     * @param  ZkDevice  $device  Sending hardware device instance.
     * @return Response Plain text acknowledgment response ("OK\n").
     */
    protected function handleBiodata(Request $request, ZkDevice $device): Response
    {
        $body = (string) $request->getContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($body));

        if ($lines !== false) {
            foreach ($lines as $line) {
                $payload = $this->biodataParser->parseLine($line);
                if ($payload !== null) {
                    ZkBiodata::updateOrCreate(
                        [
                            'pin' => $payload->pin,
                            'bio_type' => $payload->bioType,
                            'template_index' => $payload->index,
                        ],
                        [
                            'template' => $payload->template,
                            'size' => $payload->size,
                            'is_valid' => $payload->isValid,
                            'duress' => $payload->duress,
                        ]
                    );
                }
            }
        }

        return response("OK\n", 200);
    }

    /**
     * Ingest and persist user records returned from terminal queries or userinfo pushes.
     *
     * @param  Request  $request  HTTP request containing user lines (tab-delimited or key=value).
     * @param  ZkDevice  $device  Sending hardware device instance.
     * @return Response Plain text acknowledgment response ("OK\n" or "OK: <count>\n").
     */
    protected function handleUserInfo(Request $request, ZkDevice $device): Response
    {
        $body = (string) $request->getContent();
        $records = $this->operationLogParser->parse($body);
        $count = 0;

        foreach ($records as $fields) {
            if (isset($fields['PIN'])) {
                $user = ZkUser::where('pin', $fields['PIN'])->first();
                $updateData = [];

                if (isset($fields['Name']) && $fields['Name'] !== '') {
                    $updateData['name'] = $fields['Name'];
                }
                if (isset($fields['Card']) && $fields['Card'] !== '') {
                    $updateData['card_number'] = $fields['Card'];
                }
                if (isset($fields['Passwd']) && $fields['Passwd'] !== '') {
                    $updateData['password'] = $fields['Passwd'];
                }
                if (isset($fields['Pri'])) {
                    $updateData['privilege'] = (int) $fields['Pri'];
                }
                if (isset($fields['Grp'])) {
                    $updateData['group_id'] = (int) $fields['Grp'];
                }
                if (isset($fields['TZ'])) {
                    $updateData['timezone_id'] = (int) $fields['TZ'];
                }

                if ($user !== null) {
                    if (! empty($updateData)) {
                        $user->update($updateData);
                    }
                } else {
                    $user = ZkUser::create(array_merge(['pin' => $fields['PIN']], $updateData));
                }

                $count++;
                event(new UserSynchronized($user, $device));
            }
        }

        if ($count > 0) {
            AdmsLogger::ingested($device, 'USERINFO (Query)', $count);
        }

        return response("OK: {$count}\n", 200);
    }

    /**
     * Build and render the GET OPTION FROM configuration response for a device.
     *
     * Queries the latest attendance record ID to serve as the watermark stamp, merges
     * configured delay timers, transmission flags, and timezone settings into a HandshakeConfig DTO,
     * and returns the wire protocol configuration string.
     *
     * If the device options have 'force_pull_logs' set to true, Stamp is forced to 0
     * so that the edge device re-transmits all historical attendance logs.
     *
     * @param  ZkDevice  $device  Hardware device receiving configuration options.
     * @return Response Plain text response containing the GET OPTION FROM configuration block.
     */
    protected function renderHandshakeResponse(ZkDevice $device): Response
    {
        $options = $device->options ?? [];
        $forcePull = ! empty($options['force_pull_logs']);

        if ($forcePull) {
            $stamp = 0;
            unset($options['force_pull_logs']);
            $device->update(['options' => $options]);
        } else {
            $latestLog = ZkAttendanceLog::where('device_id', $device->id)->latest('id')->first();
            $stamp = $latestLog ? (int) $latestLog->id : 0;
        }

        $config = new HandshakeConfig(
            serialNumber: $device->serial_number,
            stamp: $stamp,
            opStamp: 0,
            photoStamp: 0,
            errorDelay: (int) config('zkteco-adms-core.handshake.error_delay', 60),
            delay: (int) config('zkteco-adms-core.handshake.delay', 30),
            transTimes: (string) config('zkteco-adms-core.handshake.trans_times', '00:00;14:05'),
            transInterval: (int) config('zkteco-adms-core.handshake.trans_interval', 1),
            transFlag: (string) config('zkteco-adms-core.handshake.trans_flag', '1111111111'),
            realtime: (int) config('zkteco-adms-core.handshake.realtime', 1),
            encrypt: (int) config('zkteco-adms-core.handshake.encrypt', 0),
            timezone: $device->timezone ?? config('zkteco-adms-core.handshake.timezone', 'UTC'),
        );

        return response($config->toWireString(), 200)->header('Content-Type', 'text/plain');
    }
}

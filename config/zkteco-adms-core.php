<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Table Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix added to all core ADMS database tables.
    |
    */
    'table_prefix' => env('ZKTECO_ADMS_TABLE_PREFIX', 'zkteco_'),

    /*
    |--------------------------------------------------------------------------
    | ADMS Protocol HTTP Routes
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'prefix' => env('ZKTECO_ADMS_ROUTE_PREFIX', 'iclock'),
        'middleware' => [],
        'domain' => env('ZKTECO_ADMS_ROUTE_DOMAIN', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Handshake Options (GET OPTION FROM: <SN>)
    |--------------------------------------------------------------------------
    |
    | Dynamic configuration returned to devices during /iclock/cdata or /iclock/registry
    | initial handshakes.
    |
    */
    'handshake' => [
        'realtime' => env('ZKTECO_ADMS_REALTIME', 1),
        'delay' => env('ZKTECO_ADMS_DELAY', 30),
        'error_delay' => env('ZKTECO_ADMS_ERROR_DELAY', 60),
        'trans_interval' => env('ZKTECO_ADMS_TRANS_INTERVAL', 1),
        'trans_times' => env('ZKTECO_ADMS_TRANS_TIMES', '00:00;14:05'),
        'trans_flag' => env('ZKTECO_ADMS_TRANS_FLAG', '1111111111'),
        'encrypt' => env('ZKTECO_ADMS_ENCRYPT', 0),
        'timezone' => env('ZKTECO_ADMS_TIMEZONE', 'UTC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Advanced Protocol Logging
    |--------------------------------------------------------------------------
    |
    | Enables structured logging of raw hardware requests, device polling,
    | command delivery, execution acknowledgments, and data ingestion.
    | Set channel to 'zkteco-adms' to use the package dedicated log file
    | (storage/logs/zkteco-adms.log) or null to use the default app log.
    |
    */
    'logging' => [
        'enabled' => env('ZKTECO_ADMS_LOGGING_ENABLED', true),
        'channel' => env('ZKTECO_ADMS_LOG_CHANNEL', 'zkteco-adms'),
        'path' => env('ZKTECO_ADMS_LOG_PATH', storage_path('logs/zkteco-adms.log')),
        'days' => env('ZKTECO_ADMS_LOG_DAYS', 14),
        'level' => env('ZKTECO_ADMS_LOG_LEVEL', 'debug'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Extensible Model Mappings
    |--------------------------------------------------------------------------
    */
    'models' => [
        // 'device' => \DewanLab\LaravelZktecoAdmsCore\Models\ZkDevice::class,
    ],
];

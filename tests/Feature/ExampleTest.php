<?php

it('loads configuration properly', function () {
    expect(config('zkteco-adms-core.table_prefix'))->toBe('zkteco_')
        ->and(config('zkteco-adms-core.routes.prefix'))->toBe('iclock')
        ->and(config('zkteco-adms-core.handshake.realtime'))->toBe(1);
});

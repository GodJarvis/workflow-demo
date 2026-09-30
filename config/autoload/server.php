<?php

declare(strict_types=1);

use Hyperf\Server\Event;
use Hyperf\Server\Server;

/**
 * HTTP Server:单 worker 足够 demo 使用,协程内并发由引擎调度器自行管理
 */
return [
    'mode' => SWOOLE_BASE,
    'servers' => [
        [
            'name' => 'http',
            'type' => Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => 9501,
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
            ],
        ],
    ],
    'settings' => [
        'enable_coroutine' => true,
        'worker_num' => 1,
        'pid_file' => BASE_PATH . '/runtime/hyperf.pid',
        'log_file' => BASE_PATH . '/runtime/hyperf.log',
        'max_coroutine' => 10000,
    ],
];

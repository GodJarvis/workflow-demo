<?php

declare(strict_types=1);

use Hyperf\Server\Event;
use Hyperf\Server\Server;

/**
 * HTTP Server:worker_num=2 用于演示 Table 版回调注册表的跨 worker 唤醒。
 * mode 必须 SWOOLE_PROCESS:sendMessage(worker 间管道消息)仅 PROCESS 模式支持,
 * BASE 模式下无 worker 间通信基础设施(sendMessage 返回 false → 投递退化为慢路径)
 */

// Swoole\Table 必须在 master fork worker 之前创建,worker 才共享同一映射(L2 前提)
\App\Workflow\Engine\Waiter\TableWaiterRegistry::boot();

return [
    'mode' => SWOOLE_PROCESS,
    'servers' => [
        [
            'name' => 'http',
            'type' => Server::SERVER_HTTP,
            'host' => '0.0.0.0',
            'port' => 9501,
            'sock_type' => SWOOLE_SOCK_TCP,
            'callbacks' => [
                Event::ON_REQUEST => [Hyperf\HttpServer\Server::class, 'onRequest'],
                // Table 版注册表的翻译器:跨 worker 管道消息 → 本地 Channel push / 慢路径兜底
                Event::ON_PIPE_MESSAGE => [\App\Workflow\Engine\Waiter\TableWaiterRegistry::class, 'onPipeMessage'],
            ],
        ],
    ],
    'settings' => [
        'enable_coroutine' => true,
        'worker_num' => 2,
        'pid_file' => BASE_PATH . '/runtime/hyperf.pid',
        'log_file' => BASE_PATH . '/runtime/hyperf.log',
        'max_coroutine' => 10000,
    ],
];

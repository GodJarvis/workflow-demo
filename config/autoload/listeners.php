<?php

declare(strict_types=1);

/**
 * 显式注册事件监听器(与 @Listener 注解二选一;配置式不依赖注解扫描,更稳)
 */
return [
    // 把 Swoole Server 引用注入 TableWaiterRegistry(worker 内 sendMessage 的前提)
    \App\Listener\WorkerServerHolderListener::class,
];

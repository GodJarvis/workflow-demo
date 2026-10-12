<?php

declare(strict_types=1);

namespace App\Listener;

use App\Workflow\Engine\Waiter\TableWaiterRegistry;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeWorkerStart;

/**
 * 每个 worker 启动时,把 Swoole Server 实例注入 TableWaiterRegistry
 *
 * 为什么需要:worker 进程的 DI 容器是 fork 后重建的,容器里的 ServerFactory
 * 是未 configure 的新实例(getServer() 抛错)——跨 worker sendMessage 必须持有
 * 本 worker 的 server 引用,唯一可靠来源是 Swoole 的 onWorkerStart 事件链
 * (hyperf 在此处分发 BeforeWorkerStart,事件对象携带 $server)。
 * 注册方式:config/autoload/listeners.php 显式注册(注解扫描在部分环境下不生效)
 */
class WorkerServerHolderListener implements ListenerInterface
{
    public function listen(): array
    {
        return [BeforeWorkerStart::class];
    }

    public function process(object $event): void
    {
        if ($event instanceof BeforeWorkerStart) {
            TableWaiterRegistry::setServer($event->server);
        }
    }
}

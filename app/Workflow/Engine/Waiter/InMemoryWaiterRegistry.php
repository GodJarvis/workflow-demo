<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Swoole\Coroutine\Channel;

/**
 * L1 · 进程内注册表(demo 默认,worker_num=1 时即完整语义)
 *
 * 静态数组 + Channel:回调与等待者同进程才能命中;未命中由调用方走慢路径。
 */
class InMemoryWaiterRegistry implements CallbackWaiterRegistryInterface
{
    use LocalWaiters;

    public function register(string $taskId, Channel $channel, string $taskID, string $nodeID): void
    {
        self::localRegister($taskId, $channel, $taskID, $nodeID);
    }

    public function remove(string $taskId): void
    {
        self::localRemove($taskId);
    }

    public function deliver(string $taskId, array $outputs): string
    {
        return self::localDeliver($taskId, $outputs) ? 'in_process' : 'none';
    }

    public function count(): int
    {
        return self::localCount();
    }
}

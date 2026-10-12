<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Swoole\Coroutine\Channel;

/**
 * 本地等待者注册表(trait):进程内 taskId → Channel 的映射,四实现共享此逻辑
 *
 * PHP trait 静态属性语义:每个 use 它的类持有独立副本 —— 正是需要的
 * (每个实现/每个 worker 进程各一份本地注册表)。
 * 多 worker 场景各 worker 的静态数组天然隔离,跨 worker 靠各实现自己的介质。
 */
trait LocalWaiters
{
    /** @var array<string, array{channel: Channel, taskID: string, nodeID: string, registeredAt: float}> */
    private static array $waiters = [];

    protected static function localRegister(string $taskId, Channel $channel, string $taskID, string $nodeID): void
    {
        self::$waiters[$taskId] = [
            'channel' => $channel,
            'taskID' => $taskID,
            'nodeID' => $nodeID,
            'registeredAt' => microtime(true),
        ];
    }

    /**
     * @return array{taskID: string, nodeID: string, channel: Channel}|null
     */
    protected static function localFind(string $taskId): ?array
    {
        $waiter = self::$waiters[$taskId] ?? null;
        if ($waiter === null) {
            return null;
        }
        return ['taskID' => $waiter['taskID'], 'nodeID' => $waiter['nodeID'], 'channel' => $waiter['channel']];
    }

    protected static function localRemove(string $taskId): void
    {
        unset(self::$waiters[$taskId]);
    }

    protected static function localCount(): int
    {
        return count(self::$waiters);
    }

    /**
     * 接收端通用逻辑(翻译器回调内):本地命中 → push 唤醒;
     * 未命中(等待者在别的进程或已死)→ 触发慢路径兜底(由各实现的翻译器调用)
     */
    protected static function localDeliver(string $taskId, array $outputs): bool
    {
        $waiter = self::localFind($taskId);
        if ($waiter === null) {
            return false;
        }
        $waiter['channel']->push($outputs);
        return true;
    }
}

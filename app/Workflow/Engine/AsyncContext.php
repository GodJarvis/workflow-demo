<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

/**
 * 执行模式上下文:当前引擎执行是否运行在"任务队列消费"模式
 *
 * 队列模式(executeTask/advanceExternalTask 设置):deferred 节点抛
 *   TaskDeferredException 暂停任务、释放消费协程(等待回调的任务数不受
 *   poolSize 限制,仅受存储容量约束)
 * 同步模式(默认,HTTP run / CLI):deferred 节点协程挂起等待唤醒,
 *   短周期感知任务终态后收敛
 *
 * 计数器而非布尔:消费进程多协程并发执行任务时,A 的 leave 不能关闭 B 的模式
 * (标志在 executeTask 的同步段 enter/leave,协程切换点均在 IO,读写安全)
 */
class AsyncContext
{
    private static int $queueDepth = 0;

    public static function enterQueueMode(): void
    {
        ++self::$queueDepth;
    }

    public static function leaveQueueMode(): void
    {
        self::$queueDepth = max(0, self::$queueDepth - 1);
    }

    public static function isQueueMode(): bool
    {
        return self::$queueDepth > 0;
    }
}

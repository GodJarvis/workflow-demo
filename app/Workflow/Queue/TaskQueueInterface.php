<?php

declare(strict_types=1);

namespace App\Workflow\Queue;

/**
 * 任务队列接口:生产环境批量提交的削峰与异步消费
 *
 * 与回调注册表(Engine/Waiter/)的队列语义对照(重要教学点):
 *   Waiter 的队列   = 广播(每进程独立消费组/队列,人人都要收到唤醒事件)
 *   TaskQueue 的队列 = 竞争消费(每条任务只被一个消费者处理)——
 *                     Redis Stream 单组多 consumer / RabbitMQ work queue
 *
 * 消息类型:
 *   {type: 'run',      taskID: string}                       提交执行
 *   {type: 'callback', externalTaskId: string, outputs: array} 外部完成,推进任务
 */
interface TaskQueueInterface
{
    /**
     * 投递一条任务消息(持久化;立即返回)
     * @param array<string, mixed> $message
     */
    public function publish(array $message): bool;

    /**
     * 常驻消费:启动 $poolSize 个消费协程【竞争】消费(每条消息只被一个协程处理),
     * 逐条确认(处理完成才 ACK;失败不 ACK 留待重投 —— at-least-once)。
     * 本方法阻塞(应在独立进程的 handle() 中调用,永不返回)。
     *
     * @param callable(array<string, mixed>): void $handler
     */
    public function consume(callable $handler, int $poolSize): void;
}

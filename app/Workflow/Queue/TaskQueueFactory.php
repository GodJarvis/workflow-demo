<?php

declare(strict_types=1);

namespace App\Workflow\Queue;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 任务队列工厂:按 config workflow.task_queue 切换介质,按 $channel 区分语义队列
 *   'redis_stream'(默认)—— Redis Stream 单组竞争消费(复用 redis 服务)
 *   'rabbitmq'           —— RabbitMQ work queue(durable + persistent + ACK)
 *
 * 语义队列(run / callback 拆分,独立进程独立池):
 *   run      —— 任务执行(重且长:沙箱/LLM;池=run_pool_size,按负载扩)
 *   callback —— 回调推进(轻且快:对响应延迟敏感;池=callback_pool_size,恒定小池)
 *   物理隔离的原因:Redis Stream 同组竞争会把 run 消息分给回调进程,必须分队列;
 *   收益:回调延迟不受长任务拖累、独立扩缩容、故障隔离
 */
class TaskQueueFactory
{
    /** @param string $channel 'run' | 'callback' */
    public static function make(string $channel): TaskQueueInterface
    {
        $name = (string) (ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('workflow.task_queue', 'redis_stream'));

        return match ($name) {
            'rabbitmq' => new RabbitMqTaskQueue('workflow-' . $channel . '-queue'),
            'redis_stream' => new RedisStreamTaskQueue('workflow-' . $channel . '-queue'),
            default => throw new \RuntimeException("Unknown task_queue '{$name}' (expect redis_stream|rabbitmq)"),
        };
    }
}

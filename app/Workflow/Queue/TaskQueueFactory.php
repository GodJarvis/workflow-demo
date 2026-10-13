<?php

declare(strict_types=1);

namespace App\Workflow\Queue;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 任务队列工厂:按 config workflow.task_queue 切换实现
 *   'redis_stream'(默认)—— Redis Stream 单组竞争消费(复用 redis 服务)
 *   'rabbitmq'           —— RabbitMQ work queue(durable + persistent + ACK)
 */
class TaskQueueFactory
{
    public static function make(): TaskQueueInterface
    {
        $name = (string) (ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('workflow.task_queue', 'redis_stream'));

        return match ($name) {
            'rabbitmq' => new RabbitMqTaskQueue(),
            'redis_stream' => new RedisStreamTaskQueue(),
            default => throw new \RuntimeException("Unknown task_queue '{$name}' (expect redis_stream|rabbitmq)"),
        };
    }
}

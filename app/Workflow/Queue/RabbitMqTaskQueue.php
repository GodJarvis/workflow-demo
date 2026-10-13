<?php

declare(strict_types=1);

namespace App\Workflow\Queue;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Swoole\Coroutine;

/**
 * RabbitMQ 任务队列:work queue 模式(竞争消费 + 手动 ACK + 持久化)
 *
 * 与 Waiter 的 RabbitMqWaiterRegistry(fanout 广播 + exclusive 队列)的差异:
 *   任务队列:durable 队列 + persistent 消息(delivery_mode=2)—— 跨重启不丢任务;
 *            多消费者共同消费同一队列 = 竞争分摊(RabbitMQ 经典 work queue)
 *   回调唤醒:fanout exchange 广播,exclusive 队列随进程生灭
 */
class RabbitMqTaskQueue implements TaskQueueInterface
{
    private const QUEUE = 'workflow-task-queue';

    public function publish(array $message): bool
    {
        $connection = self::connect();
        if ($connection === null) {
            return false;
        }
        try {
            $channel = $connection->channel();
            $channel->queue_declare(self::QUEUE, false, true, false, false);   // durable
            $channel->basic_publish(
                new AMQPMessage(json_encode($message, JSON_UNESCAPED_UNICODE), [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,   // 持久化消息
                ]),
                '',
                self::QUEUE,
            );
            $channel->close();
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            try { $connection->close(); } catch (\Throwable) {}
        }
    }

    public function consume(callable $handler, int $poolSize): void
    {
        for ($i = 0; $i < $poolSize; $i++) {
            Coroutine::create(function () use ($handler): void {
                while (true) {
                    $connection = null;
                    try {
                        $connection = self::connect();
                        if ($connection === null) {
                            Coroutine::sleep(1);
                            continue;
                        }
                        $channel = $connection->channel();
                        $channel->queue_declare(self::QUEUE, false, true, false, false);
                        $channel->basic_qos(null, 1, null);   // prefetch=1:处理完再取下一条
                        $channel->basic_consume(self::QUEUE, '', false, false, false, false,
                            function (AMQPMessage $message) use ($handler): void {
                                $payload = json_decode((string) $message->getBody(), true);
                                if (is_array($payload)) {
                                    $handler($payload);   // 抛异常 = 不 ACK = 重投(at-least-once)
                                }
                                $message->ack();
                            },
                        );
                        while (count($channel->callbacks) > 0) {
                            $channel->wait();   // 阻塞读(hook 后协程挂起)
                        }
                    } catch (\Throwable) {
                        // 连接断开:重连
                    } finally {
                        try { $connection?->close(); } catch (\Throwable) {}
                    }
                    Coroutine::sleep(1);
                }
            });
        }
        (new \Swoole\Coroutine\Channel(1))->pop();   // 常驻:永不返回
    }

    private static function connect(): ?AMQPStreamConnection
    {
        try {
            $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
            $cfg = $config->get('workflow.callback_amqp', []);
            return new AMQPStreamConnection(
                (string) ($cfg['host'] ?? 'rabbitmq'),
                (int) ($cfg['port'] ?? 5672),
                (string) ($cfg['user'] ?? 'guest'),
                (string) ($cfg['password'] ?? 'guest'),
                (string) ($cfg['vhost'] ?? '/'),
            );
        } catch (\Throwable) {
            return null;
        }
    }
}

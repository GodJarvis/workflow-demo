<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * L5 · RabbitMQ 形态:fanout exchange + 每进程 exclusive 队列(广播)
 *
 * 拓扑(与 Redis PubSub 语义对称,但带 MQ 特性):
 *   exchange: workflow-callbacks(fanout,广播路由)
 *   queue:    每进程启动一个 exclusive 队列 bind 到 exchange
 *             —— exclusive = 连接断开自动删除,进程死不留孤儿(正合"每进程一个接收端")
 *   投递(任意进程):basic_publish 到 exchange → 广播到所有进程队列
 *   接收(各进程消费协程):basic_consume + 手动 ack(basic_qos prefetch=1,
 *             逐条确认——MQ 形态与 PubSub 的本质差异:消费确认/不丢/可重投)
 *
 *   与 RedisStream 的定位差异:
 *     PubSub  = 广播即忘(不落盘,延迟最低)
 *     Stream  = 持久化流 + 消费组(回溯/死信,Redis 生态)
 *     RabbitMQ= 独立消息基础设施(运维体系/集群/插件生态;生产上还可用
 *               durable 队列 + persistent 消息实现跨进程重启的可靠投递)
 *
 * 兜底策略与其它实现一致:未认领(等待者已死)由回调入口的 3s 延迟复查接管。
 * 连接参数:config workflow.callback_amqp(php-amqplib 直连,未引 hyperf/amqp 组件)。
 */
class RabbitMqWaiterRegistry implements CallbackWaiterRegistryInterface
{
    use LocalWaiters;

    private const EXCHANGE = 'workflow-callbacks';

    private static bool $consumerStarted = false;

    public function register(string $taskId, Channel $channel, string $taskID, string $nodeID): void
    {
        self::localRegister($taskId, $channel, $taskID, $nodeID);
        self::ensureConsumer();
    }

    public function remove(string $taskId): void
    {
        self::localRemove($taskId);
    }

    public function deliver(string $taskId, array $outputs): string
    {
        if (self::localDeliver($taskId, $outputs)) {
            return 'in_process';
        }
        $connection = self::connect();
        if ($connection === null) {
            return 'none';
        }
        try {
            $channel = $connection->channel();
            // 幂等声明(投递端与消费端都可能先到)
            $channel->exchange_declare(self::EXCHANGE, 'fanout', false, false, false);
            $channel->basic_publish(
                new AMQPMessage(json_encode(['taskId' => $taskId, 'outputs' => $outputs], JSON_UNESCAPED_UNICODE), [
                    'content_type' => 'application/json',
                ]),
                self::EXCHANGE,
                '',
            );
            $channel->close();
            return 'amqp';
        } catch (\Throwable) {
            return 'none';
        } finally {
            try { $connection->close(); } catch (\Throwable) {}
        }
    }

    public function count(): int
    {
        return self::localCount();
    }

    /**
     * 翻译器:本进程常驻消费协程(exclusive 队列;断线重连;手动 ack)
     * php-amqplib 是同步库,底层 fread 被 Swoole hook 协程化——wait() 期间协程挂起不占 worker
     */
    private static function ensureConsumer(): void
    {
        if (self::$consumerStarted) {
            return;
        }
        self::$consumerStarted = true;
        Coroutine::create(function (): void {
            while (true) {
                $connection = null;
                try {
                    $connection = self::connect();
                    if ($connection === null) {
                        Coroutine::sleep(1);
                        continue;
                    }
                    $channel = $connection->channel();
                    $channel->exchange_declare(self::EXCHANGE, 'fanout', false, false, false);
                    // exclusive=true:服务器生成队列名,连接断开自动删除(进程死不留孤儿)
                    [$queue] = $channel->queue_declare('', false, false, true, false);
                    $channel->queue_bind($queue, self::EXCHANGE);
                    $channel->basic_qos(null, 1, null);   // prefetch=1:逐条消费逐条确认
                    $channel->basic_consume($queue, '', false, false, false, false,
                        function (AMQPMessage $message) use ($channel): void {
                            try {
                                $payload = json_decode((string) $message->getBody(), true);
                                if (is_array($payload) && isset($payload['taskId'])) {
                                    $taskId = (string) $payload['taskId'];
                                    $outputs = is_array($payload['outputs'] ?? null) ? $payload['outputs'] : [];
                                    // 命中本地等待者 → push;未命中(等待者在别的进程/已死)→ 忽略,
                                    // 已死场景由回调入口延迟复查兜底(与 PubSub 同一策略)
                                    self::localDeliver($taskId, $outputs);
                                }
                            } finally {
                                $channel->basic_ack($message->getDeliveryTag());   // 手动确认
                            }
                        },
                    );
                    while (count($channel->callbacks) > 0) {
                        $channel->wait();   // 阻塞读(hook 后协程挂起)
                    }
                } catch (\Throwable) {
                    // 连接断开/服务重启:外层重连
                } finally {
                    try { $connection?->close(); } catch (\Throwable) {}
                }
                Coroutine::sleep(1);
            }
        });
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

<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * L4 · MQ 形态:Redis Stream + 消费组(持久化事件 / ACK 确认 / 断线补读)
 *
 * 与 PubSub 的本质差异(教学点):
 *   Pub/Sub:不落盘、离线即丢(广播即忘)—— 快路径丢了靠慢路径,延迟敏感场景够用
 *   Stream :XADD 持久化 + 每进程独立消费组(组间全量广播,组内分摊)+ XACK 确认
 *            —— 事件不丢、可回溯(XRANGE)、可死信处理(XPENDING/XCLAIM)
 *   生产等价物:RabbitMQ(fanout exchange + 每实例独立队列)/ Kafka(每实例独立 consumer group)
 *
 *   注册(任意实例):localRegister + 惰性启动本进程消费协程(独立消费组 = 广播语义)
 *   投递:XADD(事件持久化)→ 'stream'
 *   接收(各进程消费协程):localDeliver 命中 → push + XACK;未命中 → XACK 后忽略
 *   兜底:同 PubSub —— 未认领场景由回调入口的延迟复查接管(见 WorkflowApplication::callback)
 */
class RedisStreamWaiterRegistry implements CallbackWaiterRegistryInterface
{
    use LocalWaiters;

    private const STREAM_KEY = 'workflow-callbacks-stream';

    private static bool $consumerStarted = false;

    /** @var string 本进程消费组名(每组独立 = 每进程都收到全量事件) */
    private static string $group = '';

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
        $redis = WaiterRedisConnection::connect(3);
        if ($redis === null) {
            return 'none';
        }
        try {
            $redis->xAdd(self::STREAM_KEY, '*', [
                'taskId' => $taskId,
                'outputs' => json_encode($outputs, JSON_UNESCAPED_UNICODE),
            ]);
            return 'stream';
        } finally {
            $redis->close();
        }
    }

    public function count(): int
    {
        return self::localCount();
    }

    /**
     * 翻译器:本进程常驻消费协程(独立消费组;断线重连;BLOCK 读协程挂起)
     */
    private static function ensureConsumer(): void
    {
        if (self::$consumerStarted) {
            return;
        }
        self::$consumerStarted = true;
        self::$group = 'wg-' . getmypid() . '-' . bin2hex(random_bytes(3));

        Coroutine::create(function (): void {
            while (true) {
                $redis = null;
                try {
                    $redis = WaiterRedisConnection::connect(-1);
                    if ($redis === null) {
                        Coroutine::sleep(1);
                        continue;
                    }
                    // 创建消费组(已存在则忽略);MKSTREAM:流不存在时自动创建
                    try {
                        $redis->xGroup('CREATE', self::STREAM_KEY, self::$group, '0', true);
                    } catch (\Throwable) {
                        // BUSYGROUP: 组已存在(本进程组名含 pid,正常不会;防御)
                    }
                    while (true) {
                        // BLOCK 60s:无消息时协程挂起;> 从未消费处开始(新组只收投递后的新事件)
                        $messages = $redis->xReadGroup(self::$group, 'c1', [self::STREAM_KEY => '>'], 10, 60000);
                        if ($messages === false || $messages === []) {
                            continue;
                        }
                        foreach ($messages[self::STREAM_KEY] ?? [] as $messageId => $fields) {
                            try {
                                self::handleMessage($fields);
                            } finally {
                                $redis->xAck(self::STREAM_KEY, self::$group, [$messageId]);
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Redis 断开/超时:外层重连
                } finally {
                    try { $redis?->close(); } catch (\Throwable) {}
                }
                Coroutine::sleep(1);
            }
        });
    }

    /**
     * @param array<string, string> $fields
     */
    private static function handleMessage(array $fields): void
    {
        $taskId = (string) ($fields['taskId'] ?? '');
        if ($taskId === '') {
            return;
        }
        $outputs = json_decode((string) ($fields['outputs'] ?? '{}'), true);
        $outputs = is_array($outputs) ? $outputs : [];
        // 命中本地等待者 → push;未命中(等待者在别的进程/实例或已死)→ 忽略,
        // 已死场景由回调入口的延迟复查兜底(与 PubSub 同一策略)
        self::localDeliver($taskId, $outputs);
    }
}

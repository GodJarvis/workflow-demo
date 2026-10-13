<?php

declare(strict_types=1);

namespace App\Workflow\Queue;

use App\Workflow\Engine\Waiter\WaiterRedisConnection;
use Swoole\Coroutine;

/**
 * Redis Stream 任务队列:单消费组多消费者 = 竞争消费(每条任务只处理一次)
 *
 * 与 Waiter 的 RedisStreamWaiterRegistry(每进程独立组=广播)的关键差异就在组策略:
 *   任务队列:全进程共用一个组 wg-task-consumer,组内多 consumer(协程)分摊 —— 削峰与水平扩展
 *   回调唤醒:每进程一个组 —— 广播
 *
 * 可靠性:XADD 持久化;处理完成 XACK;消费进程崩溃时未 ACK 消息留在 pending,
 * 重启后由 XAUTOCLAIM 重投(生产补齐点,注释标注);demo 池消费失败不 ACK 即重投。
 */
class RedisStreamTaskQueue implements TaskQueueInterface
{
    private const STREAM_KEY = 'workflow-task-queue';

    private const GROUP = 'wg-task-consumer';

    public function publish(array $message): bool
    {
        $redis = WaiterRedisConnection::connect(3);
        if ($redis === null) {
            return false;
        }
        try {
            $redis->xAdd(self::STREAM_KEY, '*', ['payload' => json_encode($message, JSON_UNESCAPED_UNICODE)]);
            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            $redis->close();
        }
    }

    public function consume(callable $handler, int $poolSize): void
    {
        // 组只需创建一次(任一协程先建,其余吞 BUSYGROUP)
        $boot = WaiterRedisConnection::connect(3);
        if ($boot !== null) {
            try {
                $boot->xGroup('CREATE', self::STREAM_KEY, self::GROUP, '0', true);   // MKSTREAM
            } catch (\Throwable) {
                // BUSYGROUP: 已存在
            }
            $boot->close();
        }

        // poolSize 个消费协程:phpredis 连接非协程安全,每协程独立连接 + 独立 consumer 名
        for ($i = 0; $i < $poolSize; $i++) {
            Coroutine::create(function () use ($handler, $i): void {
                $consumer = 'c' . getmypid() . '-' . $i;
                while (true) {
                    $redis = null;
                    try {
                        $redis = WaiterRedisConnection::connect(-1);
                        if ($redis === null) {
                            Coroutine::sleep(1);
                            continue;
                        }
                        while (true) {
                            $messages = $redis->xReadGroup(self::GROUP, $consumer, [self::STREAM_KEY => '>'], 1, 60000);
                            if ($messages === false || $messages === []) {
                                continue;
                            }
                            foreach ($messages[self::STREAM_KEY] ?? [] as $messageId => $fields) {
                                $payload = json_decode((string) ($fields['payload'] ?? '{}'), true);
                                if (! is_array($payload)) {
                                    $redis->xAck(self::STREAM_KEY, self::GROUP, [$messageId]);   // 坏消息直接确认丢弃
                                    continue;
                                }
                                try {
                                    $handler($payload);
                                    $redis->xAck(self::STREAM_KEY, self::GROUP, [$messageId]);   // 处理成功才 ACK
                                } catch (\Throwable) {
                                    // 不 ACK:留在 pending,重启后 XAUTOCLAIM 重投(at-least-once)
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // 连接断开:外层重连
                    } finally {
                        try { $redis?->close(); } catch (\Throwable) {}
                    }
                    Coroutine::sleep(1);
                }
            });
        }
        // 消费常驻:本方法永不返回(挂起在永久 Channel 上,由进程生命周期接管)
        (new \Swoole\Coroutine\Channel(1))->pop();
    }
}

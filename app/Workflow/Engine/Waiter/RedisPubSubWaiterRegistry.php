<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Hyperf\Context\ApplicationContext;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * L3 · 多实例:Redis Pub/Sub 广播 + 常驻订阅协程(翻译器)
 *
 *   注册(任意实例):localRegister(本地)+ 惰性启动本进程订阅协程
 *   投递(任意实例):本地未命中 → PUBLISH workflow-callbacks {taskId, outputs}(广播)
 *   接收(所有实例的订阅协程):localDeliver 命中(本实例有等待者)→ push;
 *                            未命中 → 忽略(等待者在别的实例)
 *   兜底:投递丢失/等待者已死 → 由回调入口的落库事实 + 慢路径接管(快路径尽力而为)
 *
 * CLI 进程同样适用:register 惰性起的订阅协程让 CLI 也能收到跨进程唤醒
 * (实测剧本:CLI run 挂起 + HTTP 回调 → publish → CLI 订阅协程 push → CLI 进程内续跑)。
 *
 * Pub/Sub 不落盘(离线即丢)——需要可靠投递时用 RedisStreamWaiterRegistry(MQ 形态)。
 * 连接参数:config workflow.callback_redis(host/port);Swoole hook 下 phpredis 协程化。
 */
class RedisPubSubWaiterRegistry implements CallbackWaiterRegistryInterface
{
    use LocalWaiters;

    private const CHANNEL = 'workflow-callbacks';

    private static bool $subscriberStarted = false;

    public function register(string $taskId, Channel $channel, string $taskID, string $nodeID): void
    {
        self::localRegister($taskId, $channel, $taskID, $nodeID);
        self::ensureSubscriber();
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
            $published = $redis->publish(self::CHANNEL, json_encode(['taskId' => $taskId, 'outputs' => $outputs], JSON_UNESCAPED_UNICODE));
            return $published >= 0 ? 'pubsub' : 'none';
        } finally {
            $redis->close();
        }
    }

    public function count(): int
    {
        return self::localCount();
    }

    /**
     * 翻译器:本进程常驻订阅协程(首个等待者注册时惰性启动;断线重连)
     * subscribe 是长阻塞读 —— Swoole hook 下协程挂起,不占 worker
     */
    private static function ensureSubscriber(): void
    {
        if (self::$subscriberStarted) {
            return;
        }
        self::$subscriberStarted = true;
        Coroutine::create(function (): void {
            while (true) {
                $redis = null;
                try {
                    $redis = WaiterRedisConnection::connect(-1);   // READ_TIMEOUT -1:subscribe 永不超时
                    if ($redis === null) {
                        Coroutine::sleep(1);
                        continue;
                    }
                    $redis->subscribe([self::CHANNEL], function ($redis, string $chan, string $msg): void {
                        $payload = json_decode($msg, true);
                        if (! is_array($payload) || ! isset($payload['taskId'])) {
                            return;
                        }
                        $taskId = (string) $payload['taskId'];
                        $outputs = is_array($payload['outputs'] ?? null) ? $payload['outputs'] : [];
                        if (self::localDeliver($taskId, $outputs)) {
                            return;   // 本实例等待者已唤醒
                        }
                        // 未认领:两义 —— 等待者在别的实例(正常,忽略)或已死(需兜底)。
                        // 保守策略:若本进程是"回调入口进程"无法区分,交由入口侧延迟兜底;
                        // 但订阅协程无从判断,故未命中静默忽略,死等待者由慢路径入口逻辑接管
                        // (见 WorkflowApplication::callback 的投递后复查)。
                    });
                } catch (\Throwable) {
                    // 连接断开/Redis 重启:重连循环
                } finally {
                    try { $redis?->close(); } catch (\Throwable) {}
                }
                Coroutine::sleep(1);
            }
        });
    }

    /**
     * @param int $readTimeout -1 = 无限(subscribe 用)
     */
    protected static function connect(int $readTimeout): ?\Redis
    {
        try {
            $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
            $cfg = $config->get('workflow.callback_redis', []);
            $redis = new \Redis();
            $redis->connect((string) ($cfg['host'] ?? 'redis'), (int) ($cfg['port'] ?? 6379), 2);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, (float) $readTimeout);
            return $redis;
        } catch (\Throwable) {
            return null;
        }
    }
}

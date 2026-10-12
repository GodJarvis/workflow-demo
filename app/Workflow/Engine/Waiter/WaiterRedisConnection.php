<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * Waiter 系列共用的 Redis 连接工具
 * 连接参数:config workflow.callback_redis(host/port);失败返回 null(快路径尽力而为)
 */
class WaiterRedisConnection
{
    /**
     * @param int|float $readTimeout -1 = 无限阻塞(subscribe/xReadGroup 用)
     */
    public static function connect(int|float $readTimeout): ?\Redis
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

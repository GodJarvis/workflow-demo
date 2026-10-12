<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 等待者注册表工厂:按 config workflow.callback_registry 切换实现
 *
 *   'memory'       单 worker(默认)      InMemoryWaiterRegistry
 *   'table'        同机多 worker          TableWaiterRegistry(Swoole\Table + sendMessage)
 *   'redis_pubsub' 多实例/跨进程唤醒      RedisPubSubWaiterRegistry(PUBLISH 广播)
 *   'redis_stream' MQ 形态(持久化/ACK)  RedisStreamWaiterRegistry(XADD + 消费组)
 */
class WaiterRegistryFactory
{
    public static function make(): CallbackWaiterRegistryInterface
    {
        $name = (string) (ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('workflow.callback_registry', 'memory'));

        return match ($name) {
            'table' => new TableWaiterRegistry(),
            'redis_pubsub' => new RedisPubSubWaiterRegistry(),
            'redis_stream' => new RedisStreamWaiterRegistry(),
            'rabbitmq' => new RabbitMqWaiterRegistry(),
            'memory' => new InMemoryWaiterRegistry(),
            default => throw new \RuntimeException("Unknown callback_registry '{$name}' (expect memory|table|redis_pubsub|redis_stream|rabbitmq)"),
        };
    }
}

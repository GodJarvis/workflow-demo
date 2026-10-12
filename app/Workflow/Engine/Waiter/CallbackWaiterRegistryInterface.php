<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Swoole\Coroutine\Channel;

/**
 * 回调等待者注册表接口 —— 四实现按部署形态切换(config workflow.callback_registry)
 *
 * 设计不变量(教学手册03§7):
 *   ① Channel 永不出进程:跨进程流动的只有可序列化数据(taskId + outputs)
 *   ② 唤醒动作永远由等待者所在进程执行(本地 Channel push)
 *   ③ 跨进程介质只做"递信",翻译器(订阅协程/PipeMessage/消费协程)负责转成本地 push
 *   ④ 投递语义 at-most-once:快路径允许丢,正确性由"落库 + 慢路径重载"兜底
 *
 * | 实现 | 形态 | 跨进程介质 | 翻译器 |
 * | InMemory    | 单 worker(默认) | 无 | deliver 直接 find+push |
 * | Table       | 同机多 worker | Swoole\Table 路由 + sendMessage | onPipeMessage |
 * | RedisPubSub | 多实例 | Redis PUBLISH 广播 | 常驻订阅协程 |
 * | RedisStream | MQ 形态 | Redis Stream + 消费组(持久化/ACK) | 常驻消费协程 |
 */
interface CallbackWaiterRegistryInterface
{
    /**
     * 等待者侧:executor 提交外部任务后、Channel::pop 之前注册
     */
    public function register(string $taskId, Channel $channel, string $taskID, string $nodeID): void;

    /**
     * 等待者侧:协程恢复后注销(executor 的 finally 中,无论成功/超时)
     */
    public function remove(string $taskId): void;

    /**
     * 回调侧:尽力投递唤醒(at-most-once)
     * @return string 投递方式:'in_process'(同进程已 push)| 'pipe_message'|'pubsub'|'stream'(已投跨进程介质,由接收端认领或兜底)| 'none'(无人等待,调用方走慢路径)
     */
    public function deliver(string $taskId, array $outputs): string;

    /**
     * 观测:本进程内挂起中的等待者数量
     */
    public function count(): int;
}

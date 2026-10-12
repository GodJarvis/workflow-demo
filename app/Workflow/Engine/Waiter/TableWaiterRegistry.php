<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Waiter;

use Hyperf\Context\ApplicationContext;
use Hyperf\Server\ServerFactory;
use Swoole\Coroutine\Channel;
use Swoole\Table;

/**
 * L2 · 同机多 worker:Swoole\Table 路由 + sendMessage 定向投递
 *
 * Channel 依旧只进本地注册表(trait);Table 里存的是【路由凭证】taskId → workerId
 * (Table 只能存标量,存不了 Channel —— 这正是"跨进程传路由不传信箱"的体现):
 *
 *   注册(worker-1):localRegister + table.set(taskId, workerId)
 *   投递(worker-2 收回调):本地未命中 → 查 Table 拿 workerId → $server->sendMessage()
 *                          → Swoole 管道消息直达 worker-1 → onPipeMessage 翻译:
 *                          localDeliver push(命中)或慢路径兜底(未命中)
 *
 * boot() 必须在 master fork worker 之前调用(见 config/autoload/server.php 顶部),
 * 否则各 worker 会各自创建 Table,失去共享内存语义。
 * CLI 进程无 Swoole Server:sendMessage 不可用,退化为本地投递(未命中=none)。
 */
class TableWaiterRegistry implements CallbackWaiterRegistryInterface
{
    use LocalWaiters;

    private const TABLE_SIZE = 65536;

    private static ?Table $table = null;

    /** 本 worker 的 Swoole Server 引用(由 WorkerServerHolderListener 在 onWorkerStart 注入) */
    private static ?\Swoole\Server $server = null;

    /**
     * worker 启动时注入(见 app/Listener/WorkerServerHolderListener.php):
     * worker 容器重建后 ServerFactory 不可用,sendMessage 必须持有本 worker 的 server 引用
     */
    public static function setServer(\Swoole\Server $server): void
    {
        self::$server = $server;
    }

    /**
     * master 阶段调用(config/autoload/server.php 顶部);幂等
     */
    public static function boot(): void
    {
        if (self::$table !== null) {
            return;
        }
        $table = new Table(self::TABLE_SIZE);
        $table->column('worker_id', Table::TYPE_INT);
        $table->column('created_at', Table::TYPE_FLOAT);
        $table->create();
        self::$table = $table;
    }

    private static function table(): ?Table
    {
        if (self::$table === null) {
            // 兜底:未经 boot(如测试直接实例化)时惰性创建;
            // 注意 worker fork 后才创建的 Table 不跨 worker 共享(仅当前进程可见)
            self::boot();
        }
        return self::$table;
    }

    public function register(string $taskId, Channel $channel, string $taskID, string $nodeID): void
    {
        self::localRegister($taskId, $channel, $taskID, $nodeID);
        self::table()?->set($taskId, ['worker_id' => self::currentWorkerId(), 'created_at' => microtime(true)]);
    }

    public function remove(string $taskId): void
    {
        self::localRemove($taskId);
        self::table()?->del($taskId);
    }

    public function deliver(string $taskId, array $outputs): string
    {
        // 快路径优先:同进程等待者直接 push(省一次管道往返)
        if (self::localDeliver($taskId, $outputs)) {
            return 'in_process';
        }

        $entry = self::table()?->get($taskId);
        if ($entry === false) {
            return 'none';   // 路由表无记录:等待者不存在或已死 → 调用方慢路径
        }

        $server = self::swooleServer();
        if ($server === null) {
            return 'none';   // CLI 进程无 server,无法跨 worker 投递
        }

        $sent = $server->sendMessage(
            json_encode(['taskId' => $taskId, 'outputs' => $outputs], JSON_UNESCAPED_UNICODE),
            (int) $entry['worker_id'],
        );
        return $sent ? 'pipe_message' : 'none';
    }

    public function count(): int
    {
        return self::localCount();
    }

    /**
     * 翻译器:Swoole 管道消息接收端(config/autoload/server.php 的 ON_PIPE_MESSAGE 回调)
     * 命中本地等待者 → push;未命中(等待者已死)→ 慢路径兜底
     */
    public function onPipeMessage($server, int $fromWorkerId, $message): void
    {
        $payload = json_decode((string) $message, true);
        if (! is_array($payload) || ! isset($payload['taskId'])) {
            return;
        }
        $taskId = (string) $payload['taskId'];
        $outputs = is_array($payload['outputs'] ?? null) ? $payload['outputs'] : [];

        if (self::localDeliver($taskId, $outputs)) {
            return;   // 已唤醒同进程等待者,收尾(unindex)由被唤醒方的主流程完成
        }
        // 未认领:等待者已死(如 worker 刚重启)→ 触发慢路径重载推进
        \Swoole\Coroutine::create(function () use ($taskId, $outputs): void {
            \App\Workflow\Application\WorkflowApplication::handleUnclaimedCallback($taskId, $outputs);
        });
    }

    /**
     * 当前 worker id(server 环境返回 worker 编号;CLI 返回 -1 仅作记录)
     */
    private static function currentWorkerId(): int
    {
        try {
            $server = self::swooleServer();
            return $server?->getWorkerId() ?? -1;
        } catch (\Throwable) {
            return -1;
        }
    }

    /**
     * 拿 Swoole server:优先 WorkerStart 事件注入的引用(可靠);
     * CLI/未注入时尝试 ServerFactory(worker 容器重建后大概率不可用,仅作兜底)
     */
    private static function swooleServer(): ?\Swoole\Server
    {
        if (self::$server instanceof \Swoole\Server) {
            return self::$server;
        }
        try {
            $container = ApplicationContext::getContainer();
            if (! $container->has(ServerFactory::class)) {
                return null;
            }
            $server = $container->get(ServerFactory::class)->getServer();
            return $server instanceof \Swoole\Server ? $server : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

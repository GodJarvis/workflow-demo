<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\Document;
use App\Workflow\Engine\Engine;
use App\Workflow\Engine\ExecutorRegistry;
use App\Workflow\Snapshot\SnapshotCenter;
use App\Workflow\Task\TaskStore;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * 应用服务:CLI 与 HTTP Controller 的共用入口
 *
 * 组装 Document → Context → Engine,并保证引擎调度始终运行在协程容器内
 * (CLI 直调时无协程环境,HTTP 请求时已在 worker 协程中,两种情况分别处理)。
 */
class WorkflowApplication
{
    private TaskStore $tasks;

    private string $runsDir;

    private int $workflowTimeout;

    public function __construct(ConfigInterface $config)
    {
        $this->runsDir = (string) $config->get('workflow.runs_dir', BASE_PATH . '/.runs');
        $this->workflowTimeout = (int) $config->get('workflow.workflow_timeout', 300);
        $this->tasks = new TaskStore($this->runsDir);
    }

    /**
     * 同步执行一次工作流
     * @return array{taskID: string, status: string, outputs: array, error: ?string, timeCost: string, report: array}
     */
    public function run(string $workflowFile, array $inputs): array
    {
        $document = Document::fromJsonFile($workflowFile);
        $this->assertValid($document);

        $taskID = 'task_' . bin2hex(random_bytes(4));
        $this->tasks->create($taskID, (string) ($document->definition['id'] ?? pathinfo($workflowFile, PATHINFO_FILENAME)));

        $snapshots = SnapshotCenter::load($this->tasks->reportFile($taskID));
        $snapshots->init((string) ($document->definition['id'] ?? 'workflow'));

        $context = WorkflowContext::create($taskID, $document, $inputs);
        $engine = new Engine(ExecutorRegistry::default(), $this->tasks, $snapshots);

        $this->runInCoroutine(fn () => $engine->process($context), $this->workflowTimeout);

        return [
            'taskID' => $taskID,
            'status' => $context->status,
            'outputs' => $context->finalOutputs,
            'error' => $context->error,
            'timeCost' => round(($context->endTime - $context->startTime) * 1000) . 'ms',
            'report' => $snapshots->asArray(),
        ];
    }

    /**
     * 断点恢复:checkpoint 反序列化 → 重建 Document → 从中断节点继续
     */
    public function resume(string $taskID): array
    {
        $task = $this->tasks->get($taskID) ?? throw new \RuntimeException("Task not found: {$taskID}");
        $checkpoint = $this->tasks->loadCheckpoint($taskID) ?? throw new \RuntimeException("Task has no checkpoint: {$taskID}");
        if (in_array($task['status'], ['success'], true)) {
            throw new \RuntimeException("Task {$taskID} already succeeded, nothing to resume");
        }

        // DSL 路径按任务记录定位(workflow 字段存的是 workflowId,约定 examples/<id>.json)
        $workflowFile = BASE_PATH . '/examples/' . $task['workflow'] . '.json';
        $document = Document::fromJsonFile($workflowFile);

        $context = WorkflowContext::restore($checkpoint, $document);
        $snapshots = SnapshotCenter::load($this->tasks->reportFile($taskID));

        $engine = new Engine(ExecutorRegistry::default(), $this->tasks, $snapshots);
        $this->runInCoroutine(fn () => $engine->resume($context), $this->workflowTimeout);

        return [
            'taskID' => $taskID,
            'status' => $context->status,
            'outputs' => $context->finalOutputs,
            'error' => $context->error,
            'timeCost' => round(($context->endTime - $context->startTime) * 1000) . 'ms',
            'report' => $snapshots->asArray(),
        ];
    }

    public function report(string $taskID): array
    {
        $this->tasks->get($taskID) ?? throw new \RuntimeException("Task not found: {$taskID}");
        return SnapshotCenter::load($this->tasks->reportFile($taskID))->asArray();
    }

    /**
     * 外部回调处理(黄金法则全流程):
     *   ① 先落库(事实源,无论等待者死活)
     *   ② 投递唤醒:按 config 的 Registry 实现(memory/table/redis_pubsub/redis_stream)
     *      - in_process:同进程已 push,挂起协程毫秒级续跑
     *      - pipe_message/pubsub/stream:已投跨进程介质,由接收端翻译器认领;
     *        同时启动【延迟复查】协程 —— 若 3s 后工作流仍未推进(等待者已死/投递丢失),
     *        触发慢路径重载推进(completeExternalTask 幂等,双推进安全)
     *      - none:无人等待 → 本进程立即慢路径
     * 幂等:重复回调 → 索引已移除则拒绝;节点已完成 → completeExternalTask 返回现状
     *
     * @param array<string, mixed> $outputs
     * @return array<string, mixed>
     */
    public function callback(string $taskId, array $outputs): array
    {
        $index = $this->tasks->loadCallbackIndex();
        $entry = $index[$taskId] ?? throw new \RuntimeException("Callback taskId not found or already delivered: {$taskId}");
        $taskID = (string) $entry['taskID'];
        $nodeID = (string) $entry['nodeID'];

        // ① 落库(永远先做;审计与幂等的事实源)
        $factState = $this->tasks->saveCallbackFact($taskID, $taskId, ['outputs' => $outputs]);

        // ② 投递唤醒(尽力而为,at-most-once)
        $registry = \App\Workflow\Engine\Waiter\WaiterRegistryFactory::make();
        $delivered = $registry->deliver($taskId, $outputs);

        if ($delivered === 'in_process') {
            $this->tasks->unindexCallback($taskId);
            return [
                'delivered' => 'in_process',
                'fact' => $factState,
                'taskID' => $taskID,
                'note' => 'fast path: suspended coroutine resumed in-process',
            ];
        }

        if ($delivered !== 'none') {
            // 已投跨进程介质:保留索引(接收端/兜底方完成后再清理);
            // 延迟复查 —— 覆盖"投递丢失"与"等待者已死"两种未认领场景
            // (投递方式可能是管道消息/广播/流,入口无法预知接收结果)
            \Swoole\Coroutine::create(function () use ($taskId, $taskID, $nodeID, $outputs, $delivered): void {
                \Swoole\Coroutine::sleep(3);
                $task = $this->tasks->get($taskID);
                $done = $task !== null && in_array($task['status'], ['success', 'failed'], true);
                if (! $done) {
                    // 快路径没有兑现(等待者死/投递丢)→ 慢路径接管(completeExternalTask 幂等:
                    // 若此刻快路径恰好也完成了,其 isExecutedNode 检查会直接返回现状,双推进安全)
                    $this->advanceExternalTask($taskID, $nodeID, $outputs);
                    $this->tasks->unindexCallback($taskId);
                }
            });
            return [
                'delivered' => $delivered,
                'fact' => $factState,
                'taskID' => $taskID,
                'note' => 'dispatched via ' . $delivered . '; unclaimed fallback armed (3s recheck)',
            ];
        }

        // none:无人等待(等待者已死/CLI 无 server 的 table 模式等)→ 本进程立即慢路径
        $result = $this->advanceExternalTask($taskID, $nodeID, $outputs);
        $this->tasks->unindexCallback($taskId);
        return [
            'delivered' => 'reloaded',
            'fact' => $factState,
            'taskID' => $taskID,
            'result' => $result,
            'note' => 'slow path: checkpoint reloaded and workflow advanced by callback handler',
        ];
    }

    /**
     * 翻译器(Table onPipeMessage / 订阅协程)发现"无人认领"时的兜底入口
     * (静态形式便于非容器上下文调用;协程内执行)
     */
    public static function handleUnclaimedCallback(string $taskId, array $outputs): void
    {
        ApplicationContext::getContainer()->get(self::class)->advanceUnclaimed($taskId, $outputs);
    }

    /**
     * 未认领兜底:索引定位 → 慢路径推进(与 callback() 的 none 分支同一逻辑)
     */
    public function advanceUnclaimed(string $taskId, array $outputs): void
    {
        $index = $this->tasks->loadCallbackIndex();
        $entry = $index[$taskId] ?? null;
        if ($entry === null) {
            return;   // 已被其它路径处理
        }
        $this->advanceExternalTask((string) $entry['taskID'], (string) $entry['nodeID'], $outputs);
        $this->tasks->unindexCallback($taskId);
    }

    /**
     * 慢路径主体:checkpoint 重载 + completeExternalTask 推进(协程安全,可在任意协程内调)
     * @return array<string, mixed>
     */
    private function advanceExternalTask(string $taskID, string $nodeID, array $outputs): array
    {
        $snapshots = SnapshotCenter::load($this->tasks->reportFile($taskID));
        $engine = new Engine(ExecutorRegistry::default(), $this->tasks, $snapshots);
        $result = ['completed' => false];
        $this->runInCoroutine(function () use ($engine, &$result, $taskID, $nodeID, $outputs): void {
            $result = $engine->completeExternalTask($taskID, $nodeID, $outputs);
        }, $this->workflowTimeout);
        return $result;
    }

    /**
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(string $workflowFile): array
    {
        $document = Document::fromJsonFile($workflowFile);
        return ['valid' => true, 'errors' => $document->validate()];
    }

    private function assertValid(Document $document): void
    {
        $errors = $document->validate();
        if ($errors !== []) {
            throw new \RuntimeException('Workflow DSL invalid: ' . implode('; ', $errors));
        }
    }

    /**
     * 保证回调运行在协程中并阻塞等待完成:
     * - 已在协程(HTTP worker)→ create + Channel 同步等待
     * - 非协程(CLI)→ Coroutine::run 新建调度器
     */
    private function runInCoroutine(callable $callback, int $timeout): void
    {
        if (Coroutine::getCid() > 0) {
            $channel = new Channel(1);
            Coroutine::create(function () use ($channel, $callback): void {
                try {
                    $callback();
                } finally {
                    $channel->push(true);
                }
            });
            $channel->pop($timeout);
            return;
        }
        Coroutine::run($callback);
    }
}

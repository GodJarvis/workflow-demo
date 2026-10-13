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
     * 异步提交(生产形态):校验 + 建 Task(queued) + inputs 持久化 + 入队
     * 毫秒级返回 taskID;执行由 WorkflowConsumerProcess(独立进程消费池)完成
     * 结果经 report 轮询 / SSE(workflow/events)获取
     */
    public function submit(string $workflowFile, array $inputs): string
    {
        $document = Document::fromJsonFile($workflowFile);
        $this->assertValid($document);

        $taskID = 'task_' . bin2hex(random_bytes(4));
        $this->tasks->create($taskID, (string) ($document->definition['id'] ?? pathinfo($workflowFile, PATHINFO_FILENAME)));
        // inputs 持久化:消费进程与提交方解耦的凭据
        file_put_contents(
            $this->tasks->taskDir($taskID) . '/inputs.json',
            json_encode(['inputs' => $inputs], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        $queued = \App\Workflow\Queue\TaskQueueFactory::make()->publish(['type' => 'run', 'taskID' => $taskID]);
        if (! $queued) {
            $this->tasks->update($taskID, ['status' => 'failed', 'error' => 'task queue publish failed']);
            throw new \RuntimeException('task queue unavailable (check workflow.task_queue config)');
        }
        return $taskID;
    }

    /**
     * 消费进程入口:加载 Task 的 DSL+inputs → 引擎执行(与同步 run 同一执行核心)
     * @return array<string, mixed>
     */
    public function executeTask(string $taskID): array
    {
        $task = $this->tasks->get($taskID) ?? throw new \RuntimeException("Task not found: {$taskID}");
        $workflowFile = BASE_PATH . '/examples/' . $task['workflow'] . '.json';
        $document = Document::fromJsonFile($workflowFile);

        $inputs = [];
        $inputsFile = $this->tasks->taskDir($taskID) . '/inputs.json';
        if (is_file($inputsFile)) {
            $decoded = json_decode((string) file_get_contents($inputsFile), true);
            $inputs = is_array($decoded['inputs'] ?? null) ? $decoded['inputs'] : [];
        }

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
        ];
    }

    /**
     * 消费进程处理回调消息:单点决策消灭双推进竞态
     *   ① Registry deliver(同步模式等待者即时唤醒,若可命中)→ 完成
     *   ② 未命中(异步任务无等待者/等待者已死)→ checkpoint 重载推进(幂等)
     * @param array<string, mixed> $outputs
     * @return array<string, mixed>
     */
    public function handleCallbackMessage(string $externalTaskId, array $outputs): array
    {
        $registry = \App\Workflow\Engine\Waiter\WaiterRegistryFactory::make();
        $delivered = $registry->deliver($externalTaskId, $outputs);
        if ($delivered !== 'none') {
            // 同步等待者已被唤醒(等待方主流程收尾)
            $this->tasks->unindexCallback($externalTaskId);
            return ['delivered' => $delivered, 'note' => 'waiter resumed'];
        }
        return $this->advanceUnclaimed($externalTaskId, $outputs);
    }

    /**
     * 外部回调处理(黄金法则:API 只做落库+入队,推进在消费进程)
     *   ① 落库(事实源,无论后续如何)
     *   ② 投递"继续消息"到任务队列 → 消费者单点推进(削峰+同任务 FIFO 串行化)
     * @param array<string, mixed> $outputs
     * @return array<string, mixed>
     */
    public function callback(string $taskId, array $outputs): array
    {
        $index = $this->tasks->loadCallbackIndex();
        $entry = $index[$taskId] ?? throw new \RuntimeException("Callback taskId not found or already delivered: {$taskId}");
        $taskID = (string) $entry['taskID'];

        // ① 落库(审计与幂等的事实源)
        $factState = $this->tasks->saveCallbackFact($taskID, $taskId, ['outputs' => $outputs]);

        // ② 入队(毫秒级返回;推进由消费进程 handleCallbackMessage 单点执行)
        $queued = \App\Workflow\Queue\TaskQueueFactory::make()->publish([
            'type' => 'callback',
            'externalTaskId' => $taskId,
            'outputs' => $outputs,
        ]);
        if (! $queued) {
            throw new \RuntimeException('task queue unavailable on callback dispatch');
        }

        return [
            'queued' => true,
            'fact' => $factState,
            'taskID' => $taskID,
            'note' => 'dispatched to task queue; consumer will advance the workflow',
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

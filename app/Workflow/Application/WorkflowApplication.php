<?php

declare(strict_types=1);

namespace App\Workflow\Application;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\Document;
use App\Workflow\Engine\Engine;
use App\Workflow\Engine\ExecutorRegistry;
use App\Workflow\Snapshot\SnapshotCenter;
use App\Workflow\Task\TaskStore;
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

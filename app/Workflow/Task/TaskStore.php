<?php

declare(strict_types=1);

namespace App\Workflow\Task;

/**
 * 任务存储:Task 状态机持久化(JSON 文件,demo 不引 DB)
 *
 * 状态流转:created → running → success / failed
 * 落盘结构 .runs/<taskID>/task.json
 */
class TaskStore
{
    public function __construct(private readonly string $runsDir)
    {
    }

    public function create(string $taskID, string $workflowId): array
    {
        $task = [
            'taskID' => $taskID,
            'workflow' => $workflowId,
            'status' => 'created',
            'startTime' => microtime(true),
            'endTime' => null,
            'error' => null,
        ];
        $this->write($taskID, $task);
        return $task;
    }

    public function update(string $taskID, array $fields): void
    {
        $task = $this->get($taskID);
        if ($task === null) {
            return;
        }
        $this->write($taskID, array_merge($task, $fields));
    }

    public function get(string $taskID): ?array
    {
        $file = $this->taskFile($taskID);
        if (! is_file($file)) {
            return null;
        }
        return json_decode((string) file_get_contents($file), true);
    }

    public function taskDir(string $taskID): string
    {
        $dir = rtrim($this->runsDir, '/') . '/' . $taskID;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public function reportFile(string $taskID): string
    {
        return $this->taskDir($taskID) . '/report.json';
    }

    public function checkpointFile(string $taskID): string
    {
        return $this->taskDir($taskID) . '/checkpoint.json';
    }

    /**
     * 读取最后 checkpoint(可序列化 Context 的落盘形态)
     */
    public function loadCheckpoint(string $taskID): ?array
    {
        $file = $this->checkpointFile($taskID);
        if (! is_file($file)) {
            return null;
        }
        return json_decode((string) file_get_contents($file), true);
    }

    public function saveCheckpoint(string $taskID, array $serializedContext): void
    {
        file_put_contents($this->checkpointFile($taskID), json_encode($serializedContext, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    // ------------------------------------------------- 外部回调索引与事实(JSON 文件;生产替换点:Redis Hash / DB)

    /**
     * 全局回调索引:taskId → {taskID, nodeID, createdAt}
     * 回调端点只有 taskId,靠它定位工作流(demo 用单文件;生产 Redis Hash,替换点仅此一组方法)
     * @return array<string, array{taskID: string, nodeID: string, createdAt: float}>
     */
    public function loadCallbackIndex(): array
    {
        $file = rtrim($this->runsDir, '/') . '/callback-index.json';
        if (! is_file($file)) {
            return [];
        }
        return json_decode((string) file_get_contents($file), true) ?: [];
    }

    public function saveCallbackIndex(array $index): void
    {
        file_put_contents(rtrim($this->runsDir, '/') . '/callback-index.json', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function indexCallback(string $taskId, string $taskID, string $nodeID, ?string $pollUrl = null, ?float $deadline = null, ?bool $queued = null): void
    {
        $index = $this->loadCallbackIndex();
        $index[$taskId] = [
            'taskID' => $taskID,
            'nodeID' => $nodeID,
            'createdAt' => microtime(true),
            // 对账兜底凭据:pollUrl=外部状态查询句柄({taskId} 占位);deadline=回调丢失超时线
            'pollUrl' => $pollUrl,
            'deadline' => $deadline,
            // 任务注册时是否为队列模式:queued=true 时回调方跳过广播投递(必然空喊,
            // 无任何订阅者——等待者在 register 之前就抛 TaskDeferredException 释放了)
            'queued' => $queued,
        ];
        $this->saveCallbackIndex($index);
    }

    public function unindexCallback(string $taskId): void
    {
        $index = $this->loadCallbackIndex();
        unset($index[$taskId]);
        $this->saveCallbackIndex($index);
    }

    /**
     * 回调事实落库(黄金法则第一步:无论等待者死活,先持久化事实)
     * 落库路径:.runs/<taskID>/callbacks/<taskId>.json(审计/幂等判断)
     */
    public function saveCallbackFact(string $taskID, string $taskId, array $payload): string
    {
        $dir = $this->taskDir($taskID) . '/callbacks';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/' . $taskId . '.json';
        $exists = is_file($file);
        file_put_contents($file, json_encode(
            ['taskId' => $taskId, 'receivedAt' => microtime(true)] + $payload,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ));
        return $exists ? 'duplicated' : 'stored';
    }

    public function hasCallbackFact(string $taskID, string $taskId): bool
    {
        return is_file($this->taskDir($taskID) . '/callbacks/' . $taskId . '.json');
    }

    private function taskFile(string $taskID): string
    {
        return $this->taskDir($taskID) . '/task.json';
    }

    private function write(string $taskID, array $task): void
    {
        file_put_contents($this->taskFile($taskID), json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

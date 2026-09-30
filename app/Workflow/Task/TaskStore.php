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

    private function taskFile(string $taskID): string
    {
        return $this->taskDir($taskID) . '/task.json';
    }

    private function write(string $taskID, array $task): void
    {
        file_put_contents($this->taskFile($taskID), json_encode($task, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

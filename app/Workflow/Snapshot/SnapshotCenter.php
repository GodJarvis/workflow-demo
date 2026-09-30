<?php

declare(strict_types=1);

namespace App\Workflow\Snapshot;

/**
 * 快照中心:逐节点 inputs/outputs/branch/logs/耗时 落盘
 *
 * 对应 runtime-js 的 SnapshotCenter + ReportCenter,也是编辑器调试面板的数据来源。
 * demo 采用"内存聚合 + 每次全量重写 report.json",简单可靠且天然支持中途查看。
 */
class SnapshotCenter
{
    /** @var array<string, array<string, mixed>> */
    private array $report = [];

    public function __construct(private readonly string $reportFile)
    {
    }

    public function init(string $workflowId): void
    {
        $this->report = [
            'workflow' => $workflowId,
            'nodes' => [],
        ];
        $this->flush();
    }

    public function createNodeSnapshot(string $nodeID, array $data): void
    {
        $this->report['nodes'][$nodeID] = [
            'status' => 'processing',
            'startTime' => microtime(true),
            'data' => $data,
        ];
        $this->flush();
    }

    /**
     * 确保节点快照存在(分支裁剪的 skipped 节点未经 executeNode,需兜底建档)
     */
    public function ensureNodeSnapshot(string $nodeID): void
    {
        if (! isset($this->report['nodes'][$nodeID])) {
            $this->report['nodes'][$nodeID] = [
                'status' => 'pending',
                'startTime' => microtime(true),
                'data' => [],
            ];
            $this->flush();
        }
    }

    public function updateNodeSnapshot(string $nodeID, array $fields): void
    {
        if (! isset($this->report['nodes'][$nodeID])) {
            return;
        }
        $node = &$this->report['nodes'][$nodeID];
        foreach ($fields as $key => $value) {
            $node[$key] = $value;
        }
        if (array_key_exists('status', $fields) && in_array($fields['status'], ['success', 'failed', 'skipped'], true)) {
            $node['endTime'] = microtime(true);
            $node['timeCost'] = round(($node['endTime'] - $node['startTime']) * 1000) . 'ms';
        }
        $this->flush();
    }

    /** 追加节点日志(来自沙箱 stderr 的 JSON Lines) */
    public function appendLogs(string $nodeID, array $logs): void
    {
        if ($logs === []) {
            return;
        }
        $this->report['nodes'][$nodeID]['logs'] = array_merge(
            $this->report['nodes'][$nodeID]['logs'] ?? [],
            $logs,
        );
        $this->flush();
    }

    /**
     * 沙箱 stderr 原文兜底保存(便于 JSON Lines 解析失败时排查)
     */
    public function setRawStderr(string $nodeID, string $stderr): void
    {
        if (trim($stderr) !== '') {
            $this->report['nodes'][$nodeID]['rawStderr'] = mb_substr($stderr, -4000);
        }
        $this->flush();
    }

    public function asArray(): array
    {
        return $this->report;
    }

    public static function load(string $reportFile): self
    {
        $self = new self($reportFile);
        if (is_file($reportFile)) {
            $self->report = json_decode((string) file_get_contents($reportFile), true) ?: [];
        }
        return $self;
    }

    private function flush(): void
    {
        $dir = dirname($this->reportFile);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->reportFile, json_encode($this->report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

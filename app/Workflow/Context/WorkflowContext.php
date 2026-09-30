<?php

declare(strict_types=1);

namespace App\Workflow\Context;

use App\Workflow\Document\Document;
use App\Workflow\State\WorkflowState;
use App\Workflow\Variable\VariableStore;

/**
 * 执行上下文:一次任务运行的全部可变状态
 *
 * ① 号核心承诺 —— 可序列化 Context:
 *   serialize() 导出变量存储 + 已执行集合 + 节点状态 + 终态输出,
 *   每个节点执行成功后落盘为 checkpoint.json;
 *   resume 时 restore() + 重建 Document,从中断处继续(Engine::resume)。
 */
class WorkflowContext
{
    public string $status = 'created';

    public ?string $error = null;

    public float $startTime = 0.0;

    public float $endTime = 0.0;

    /** @var array<string, string> 节点 id => 状态(pending/processing/success/failed/skipped) */
    public array $nodeStatuses = [];

    /** @var array<string, mixed> end 节点收集的工作流最终输出 */
    public array $finalOutputs = [];

    private WorkflowState $state;

    private function __construct(
        public readonly string $taskID,
        public readonly Document $document,
    ) {
        $this->state = new WorkflowState(new VariableStore());
        foreach ($document->allNodeIds() as $id) {
            $this->nodeStatuses[$id] = 'pending';
        }
    }

    public static function create(string $taskID, Document $document, array $inputs): self
    {
        $ctx = new self($taskID, $document);
        $ctx->state->getVariables()->setGlobalInputs($inputs);
        return $ctx;
    }

    public function getState(): WorkflowState
    {
        return $this->state;
    }

    public function isTerminated(): bool
    {
        return in_array($this->status, ['success', 'failed', 'cancelled'], true);
    }

    // ---------------------------------------------------------------- 可序列化

    /**
     * 导出全部可变状态(不含 Document —— 它是不可变的 DSL 视图,resume 时重建)
     */
    public function serialize(): array
    {
        return [
            'taskID' => $this->taskID,
            'status' => $this->status,
            'error' => $this->error,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'nodeStatuses' => $this->nodeStatuses,
            'finalOutputs' => $this->finalOutputs,
            'variables' => $this->state->getVariables()->toArray(),
            'executedNodes' => $this->state->executedNodeIds(),
        ];
    }

    /**
     * 从 checkpoint 恢复:变量存储与已执行集合原样还原,
     * resume 侧据此找出"前驱已满足但未执行"的节点重新驱动
     */
    public static function restore(array $data, Document $document): self
    {
        $ctx = new self($data['taskID'], $document);
        $ctx->status = $data['status'] ?? 'created';
        $ctx->error = $data['error'] ?? null;
        $ctx->startTime = $data['startTime'] ?? 0.0;
        $ctx->endTime = $data['endTime'] ?? 0.0;
        $ctx->nodeStatuses = $data['nodeStatuses'] ?? $ctx->nodeStatuses;
        $ctx->finalOutputs = $data['finalOutputs'] ?? [];

        $variables = VariableStore::fromArray($data['variables'] ?? []);
        // 还原到新 State(绕过构造注入,直接重建)
        $restoreState = new \ReflectionMethod($ctx, 'restoreState');
        $restoreState->invoke($ctx, new WorkflowState($variables));
        $ctx->state->restoreExecutedNodes($data['executedNodes'] ?? []);
        return $ctx;
    }

    private function restoreState(WorkflowState $state): void
    {
        $this->state = $state;
    }
}

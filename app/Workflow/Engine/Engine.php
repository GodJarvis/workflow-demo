<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\Document;
use App\Workflow\Document\NodeModel;
use App\Workflow\Snapshot\SnapshotCenter;
use App\Workflow\Task\TaskStore;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

/**
 * 调度器:runtime-js WorkflowRuntimeEngine 的 PHP 协程翻译
 *
 * 执行模型(与 FlowGram 一致,非拓扑排序):
 *   executeNode(start)
 *     ① canExecute:所有前驱都执行过?否则静默 return(等另一条分支来触发 —— 汇聚语义)
 *     ② 解析输入 → executor 执行 → 写变量存储 → checkpoint 落盘
 *     ③ getNextNodes:按 branch 裁剪;被剪分支的后代直接标记 executed(防汇聚死等)
 *     ④ executeNext:Coroutine::create 并发推进每个后继,WaitGroup 等待全部子树完成
 */
class Engine
{
    public function __construct(
        private readonly ExecutorRegistry $registry,
        private readonly TaskStore $tasks,
        private readonly SnapshotCenter $snapshots,
    ) {
    }

    /**
     * 首次执行:从 start 节点驱动(必须在协程容器内调用)
     */
    public function process(WorkflowContext $context): void
    {
        $context->status = 'running';
        $context->startTime = microtime(true);
        $this->tasks->update($context->taskID, ['status' => 'running']);

        try {
            $this->executeNode($context, $context->document->getStartNode());
            // start 无后继等异常场景兜底:只要没终止且没到 end,由递归内的 throw 处理
            if (! $context->isTerminated()) {
                $context->status = 'success';
            }
        } catch (\Throwable $e) {
            $context->status = 'failed';
            $context->error = $e->getMessage();
        } finally {
            $context->endTime = microtime(true);
            $this->tasks->update($context->taskID, [
                'status' => $context->status,
                'endTime' => $context->endTime,
                'error' => $context->error,
            ]);
            $this->checkpoint($context);
        }
    }

    /**
     * 断点恢复:从 checkpoint 还原状态,重新驱动所有"前驱已满足但未执行"的节点
     */
    public function resume(WorkflowContext $context): void
    {
        $context->status = 'running';
        if ($context->startTime <= 0.0) {
            $context->startTime = microtime(true);
        }
        $this->tasks->update($context->taskID, ['status' => 'running']);

        try {
            $pending = [];
            foreach ($context->document->allNodes() as $node) {
                if ($context->getState()->isExecutedNode($node)) {
                    continue;
                }
                if ($this->canExecuteNode($context, $node)) {
                    $pending[] = $node;
                }
            }
            $wg = new WaitGroup();
            foreach ($pending as $node) {
                $wg->add();
                Coroutine::create(function () use ($wg, $context, $node): void {
                    try {
                        $this->executeNode($context, $node);
                    } catch (\Throwable) {
                        // 错误由主协程汇总,这里吞掉避免 WaitGroup 挂起
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();
            if (! $context->isTerminated()) {
                $context->status = 'success';
            }
        } catch (\Throwable $e) {
            $context->status = 'failed';
            $context->error = $e->getMessage();
        } finally {
            $context->endTime = microtime(true);
            $this->tasks->update($context->taskID, [
                'status' => $context->status,
                'endTime' => $context->endTime,
                'error' => $context->error,
            ]);
            $this->checkpoint($context);
        }
    }

    /**
     * 单节点执行(递归核心)
     */
    private function executeNode(WorkflowContext $context, NodeModel $node): void
    {
        // 其他分支失败后尽快退出在途协程
        if ($context->isTerminated()) {
            return;
        }

        // ① 汇聚判断:前驱未全部执行 → 静默跳过,等最后一条分支推进到此处时再触发
        if (! $this->canExecuteNode($context, $node)) {
            return;
        }

        // 已执行(多个前驱汇聚时会被重复触发)直接跳过
        if ($context->getState()->isExecutedNode($node)) {
            return;
        }

        $context->nodeStatuses[$node->id] = 'processing';
        $this->snapshots->createNodeSnapshot($node->id, $node->data);

        try {
            $inputs = $context->getState()->getNodeInputs($node);
            $this->snapshots->updateNodeSnapshot($node->id, ['inputs' => $inputs]);

            // ② 委派执行器
            $executor = $this->registry->get($node->type);
            $result = $executor->execute($context, $node, $inputs);

            if ($context->isTerminated()) {
                return;
            }

            $context->getState()->setNodeOutputs($node, $result['outputs']);
            $context->getState()->addExecutedNode($node);
            $context->nodeStatuses[$node->id] = 'success';
            $this->snapshots->updateNodeSnapshot($node->id, [
                'outputs' => $result['outputs'],
                'branch' => $result['branch'] ?? null,
                'status' => 'success',
            ]);
            // 沙箱日志(代码节点 stderr JSON Lines)写入节点快照
            if (($result['logs'] ?? []) !== []) {
                $this->snapshots->appendLogs($node->id, $result['logs']);
            }
            // 每节点成功后落 checkpoint(可序列化 Context 的写入时机)
            $this->checkpoint($context);
        } catch (\Throwable $e) {
            $context->nodeStatuses[$node->id] = 'failed';
            $this->snapshots->updateNodeSnapshot($node->id, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // ③④ 分支裁剪 + 并发推进
        $nextNodes = $this->getNextNodes($context, $node, $result['branch'] ?? null);
        $this->executeNext($context, $node, $nextNodes);
    }

    /**
     * 前驱全执行检查(runtime-js canExecuteNode)
     */
    private function canExecuteNode(WorkflowContext $context, NodeModel $node): bool
    {
        foreach ($node->prev as $prevId) {
            $prev = $context->document->getNode($prevId);
            if ($prev !== null && ! $context->getState()->isExecutedNode($prev)) {
                return false;
            }
        }
        return true;
    }

    /**
     * 分支裁剪(runtime-js getNextNodes):
     * - 无 branch:走全部后继
     * - 有 branch:只走端口匹配的后继;被剪分支的"独占后代"直接标记 executed + skipped,
     *   否则下游汇聚点会永远等一个不会执行的节点(死等)
     *
     * @return NodeModel[]
     */
    private function getNextNodes(WorkflowContext $context, NodeModel $node, ?string $branch): array
    {
        $allNext = [];
        foreach ($node->nextNodeIds() as $nextId) {
            $allNext[] = $context->document->getNode($nextId);
        }

        if ($branch === null) {
            return $allNext;
        }

        $selected = [];
        $skipped = [];
        foreach ($node->next as $edge) {
            if ($edge['port'] === $branch) {
                $selected[$edge['node']] = true;
            } else {
                $skipped[$edge['node']] = true;
            }
        }

        // 选中分支可达集合 A / 被剪分支可达集合 B;仅存在于 B 的节点标记 skipped
        $reachableA = [];
        foreach (array_keys($selected) as $nextId) {
            $reachableA += $context->document->reachableClosure($nextId);
        }
        foreach (array_keys($skipped) as $nextId) {
            foreach ($context->document->reachableClosure($nextId) as $skipId => $_) {
                if (! isset($reachableA[$skipId])) {
                    $skippedNode = $context->document->getNode($skipId);
                    if ($skippedNode !== null && ! $context->getState()->isExecutedNode($skippedNode)) {
                        $context->getState()->addExecutedNode($skippedNode);
                        $context->nodeStatuses[$skipId] = 'skipped';
                        $this->snapshots->ensureNodeSnapshot($skipId);
                        $this->snapshots->updateNodeSnapshot($skipId, ['status' => 'skipped']);
                    }
                }
            }
        }

        $result = [];
        foreach ($allNext as $nextNode) {
            if (isset($selected[$nextNode->id])) {
                $result[] = $nextNode;
            }
        }
        return $result;
    }

    /**
     * 终止型节点(end)不再推进;其余节点无后继视为 DSL 错误;
     * 每个后继一个协程,WaitGroup 等待全部子树完成(= runtime-js 的 Promise.all)
     *
     * @param NodeModel[] $nextNodes
     */
    private function executeNext(WorkflowContext $context, NodeModel $node, array $nextNodes): void
    {
        if (in_array($node->type, ['end'], true)) {
            return;
        }
        if ($nextNodes === []) {
            throw new \RuntimeException("Node '{$node->id}' has no next nodes");
        }

        $wg = new WaitGroup();
        foreach ($nextNodes as $nextNode) {
            $wg->add();
            Coroutine::create(function () use ($wg, $context, $nextNode): void {
                try {
                    $this->executeNode($context, $nextNode);
                } catch (\Throwable) {
                    // 失败冒泡:标记一次终态即可,由各层 finally 收敛状态
                    if (! $context->isTerminated()) {
                        $context->status = 'failed';
                        $context->error = "node {$nextNode->id} failed";
                    }
                } finally {
                    $wg->done();
                }
            });
        }
        $wg->wait();
    }

    private function checkpoint(WorkflowContext $context): void
    {
        $this->tasks->saveCheckpoint($context->taskID, $context->serialize());
    }
}

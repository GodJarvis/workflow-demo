<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;

/**
 * 节点执行器统一协议(对应 runtime-js 的 Executor 接口):
 * - 返回 outputs:写入变量存储,供下游 ref 引用
 * - 可选返回 branch:分支标识(仅 condition 类),由引擎据此裁剪边
 * - 控制流(汇聚/裁剪/并行)全部归 Engine,执行器只做副作用
 */
interface NodeExecutorInterface
{
    public function type(): string;

    /**
     * @param array<string, mixed> $inputs 已解析的节点输入(State::getNodeInputs 产物)
     * @return array{outputs: array<string, mixed>, branch?: string}
     */
    public function execute(WorkflowContext $context, NodeModel $node, array $inputs): array;
}

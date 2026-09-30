<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Executors;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;
use App\Workflow\Engine\NodeExecutorInterface;

/**
 * End 节点:收集最终输出(其 inputsValues 可引用任意上游节点变量),终止工作流
 */
class EndExecutor implements NodeExecutorInterface
{
    public function type(): string
    {
        return 'end';
    }

    public function execute(WorkflowContext $context, NodeModel $node, array $inputs): array
    {
        $context->finalOutputs = $inputs;
        return ['outputs' => $inputs];
    }
}

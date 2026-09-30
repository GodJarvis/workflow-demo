<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Executors;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;
use App\Workflow\Engine\NodeExecutorInterface;

/**
 * Start 节点:输入透传为输出(输入已由 Context::create 写入 global/start 域)
 */
class StartExecutor implements NodeExecutorInterface
{
    public function type(): string
    {
        return 'start';
    }

    public function execute(WorkflowContext $context, NodeModel $node, array $inputs): array
    {
        return ['outputs' => $inputs];
    }
}

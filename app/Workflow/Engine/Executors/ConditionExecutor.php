<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Executors;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;
use App\Workflow\Engine\NodeExecutorInterface;

/**
 * 条件节点:按 cases 顺序求值,返回第一个命中的 branch;无 when 的 case 为兜底分支
 *
 * 只做"判断",不做"裁剪" —— 裁剪由 Engine::getNextNodes 按 fromPort 完成(控制流与业务解耦)
 */
class ConditionExecutor implements NodeExecutorInterface
{
    public function type(): string
    {
        return 'condition';
    }

    public function execute(WorkflowContext $context, NodeModel $node, array $inputs): array
    {
        $cases = $node->data['cases'] ?? [];
        $state = $context->getState();

        foreach ($cases as $case) {
            $when = $case['when'] ?? null;
            // 兜底分支(无 when)直接命中
            if ($when === null) {
                return ['outputs' => [], 'branch' => (string) $case['branch']];
            }
            $left = $state->parseFlowValue($when['left']);
            $right = $state->parseFlowValue($when['right']);
            if ($this->compare($left, (string) $when['op'], $right)) {
                return ['outputs' => [], 'branch' => (string) $case['branch']];
            }
        }
        throw new \RuntimeException("Condition node '{$node->id}': no case matched and no default branch declared");
    }

    private function compare(mixed $left, string $op, mixed $right): bool
    {
        return match ($op) {
            'eq' => $left == $right,
            'ne' => $left != $right,
            'gt', 'gte', 'lt', 'lte' => $this->numericCompare($left, $right, $op),
            'contains' => is_string($left) && is_string($right) && str_contains($left, $right)
                || (is_array($left) && in_array($right, $left, true)),
            'exists' => $left !== null,
            default => throw new \RuntimeException("Unsupported condition operator '{$op}'"),
        };
    }

    private function numericCompare(mixed $left, mixed $right, string $op): bool
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return false;
        }
        $l = (float) $left;
        $r = (float) $right;
        return match ($op) {
            'gt' => $l > $r,
            'gte' => $l >= $r,
            'lt' => $l < $r,
            'lte' => $l <= $r,
            default => false,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Workflow\State;

use App\Workflow\Document\NodeModel;
use App\Workflow\Variable\VariableStore;

/**
 * 工作流状态:变量解析 + 已执行节点集合
 *
 * 对应 runtime-js 的 WorkflowRuntimeState:
 * - parseFlowValue:三种变量形态 constant / ref / template 的统一解析入口
 * - executedNodes:汇聚判断与分支裁剪的依据(可序列化)
 */
class WorkflowState
{
    /** @var array<string, true> 已执行节点集合(含被分支裁剪"跳过"标记的节点) */
    private array $executedNodes = [];

    public function __construct(private readonly VariableStore $variables)
    {
    }

    public function getVariables(): VariableStore
    {
        return $this->variables;
    }

    public function isExecutedNode(NodeModel $node): bool
    {
        return isset($this->executedNodes[$node->id]);
    }

    public function addExecutedNode(NodeModel $node): void
    {
        $this->executedNodes[$node->id] = true;
    }

    /**
     * 解析节点输入:按 outputsSchema 声明,逐字段解析 inputsValues 中的流值
     * @return array<string, mixed>
     */
    public function getNodeInputs(NodeModel $node): array
    {
        $declares = $node->data['inputsSchema']['properties'] ?? [];
        $values = $node->data['inputsValues'] ?? [];
        $inputs = [];

        foreach ($values as $key => $flowValue) {
            if (! array_key_exists($key, $declares)) {
                continue; // 未声明字段直接忽略,与 runtime-js 行为一致
            }
            $parsed = $this->parseFlowValue($flowValue);
            if ($parsed !== null) {
                $inputs[$key] = $parsed;
            }
        }
        return $inputs;
    }

    /**
     * 将节点执行输出写入变量存储(下游 ref 引用的写入侧)
     * @param array<string, mixed> $outputs
     */
    public function setNodeOutputs(NodeModel $node, array $outputs): void
    {
        $declares = $node->data['outputsSchema']['properties'] ?? [];
        if ($declares === []) {
            // 未声明 outputsSchema 时整体以 output 为 key 存入,保证 ref 仍可引用
            $this->variables->setVariable($node->id, 'output', $outputs);
            return;
        }
        foreach ($declares as $key => $schema) {
            $this->variables->setVariable(
                $node->id,
                $key,
                array_key_exists($key, $outputs) ? $outputs[$key] : ($schema['default'] ?? null),
                $schema['type'] ?? null,
                $schema['items']['type'] ?? null,
            );
        }
    }

    /**
     * 三种流值形态解析:
     * - constant:直接取 content
     * - ref:     [nodeID, key, ...path] → VariableStore 查询
     * - template:"{{nodeID.key.path}}" → 正则逐段替换(值为 null 的段替换为空串)
     */
    public function parseFlowValue(array $flowValue): mixed
    {
        return match ($flowValue['type'] ?? null) {
            'constant' => $flowValue['content'] ?? null,
            'ref' => $this->parseRef($flowValue),
            'template' => $this->parseTemplate($flowValue),
            default => throw new \RuntimeException('Unknown flow value type: ' . ($flowValue['type'] ?? 'null')),
        };
    }

    /**
     * ref 解析:content = [nodeID, variableKey, ...path]
     */
    public function parseRef(array $ref): mixed
    {
        $content = $ref['content'] ?? [];
        if (! is_array($content) || count($content) < 2) {
            return null;
        }
        [$nodeID, $variableKey] = $content;
        $path = array_slice($content, 2);
        $result = $this->variables->getValue((string) $nodeID, (string) $variableKey, $path);
        return $result['value'] ?? null;
    }

    /**
     * 模板解析:"前缀{{node.key.path}}后缀" → 逐个 ref 替换为字符串
     */
    public function parseTemplate(array $template): string
    {
        $content = (string) ($template['content'] ?? '');
        return preg_replace_callback(
            '/\{\{([^}]+)\}\}/',
            function (array $match): string {
                $segments = array_map('trim', explode('.', $match[1]));
                if (count($segments) < 2) {
                    return '';
                }
                $value = $this->parseRef(['type' => 'ref', 'content' => $segments]);
                return $value === null ? '' : (is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE));
            },
            $content,
        );
    }

    /** @return string[] */
    public function executedNodeIds(): array
    {
        return array_keys($this->executedNodes);
    }

    /** @param string[] $ids */
    public function restoreExecutedNodes(array $ids): void
    {
        $this->executedNodes = array_fill_keys($ids, true);
    }
}

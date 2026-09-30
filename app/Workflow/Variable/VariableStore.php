<?php

declare(strict_types=1);

namespace App\Workflow\Variable;

/**
 * 变量存储:数据流的核心
 *
 * 对应 runtime-js 的 WorkflowRuntimeVariableStore:
 *   Map<nodeID, Map<key, {value, type, itemsType}>>
 * 每个节点的输出按 nodeID 隔离,下游通过 ref([nodeID, key, ...path]) 引用。
 * 全部状态保存在纯数组中,天然可 json 序列化(可序列化 Context 的基础)。
 */
class VariableStore
{
    /** @var array<string, array<string, array{value: mixed, type: ?string, itemsType: ?string}>> */
    private array $store = [];

    public function setVariable(string $nodeID, string $key, mixed $value, ?string $type = null, ?string $itemsType = null): void
    {
        $this->store[$nodeID][$key] = [
            'value' => $value,
            'type' => $type,
            'itemsType' => $itemsType,
        ];
    }

    /**
     * 按 [nodeID, key, ...path] 取变量,path 支持 a.b.0.c 风格的嵌套路径
     */
    public function getValue(string $nodeID, string $variableKey, array $variablePath = []): ?array
    {
        $variable = $this->store[$nodeID][$variableKey] ?? null;
        if ($variable === null) {
            return null;
        }
        $value = $variable['value'];
        if ($variablePath !== []) {
            // 数组路径逐级下钻,任一层缺失返回 null
            foreach ($variablePath as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    return null;
                }
                $value = $value[$segment];
            }
        }
        return [
            'value' => $value,
            'type' => $variable['type'],
            'itemsType' => $variable['itemsType'],
        ];
    }

    /**
     * 将 start 节点输入注册为 global 域变量,供全图引用
     * @param array<string, mixed> $inputs
     */
    public function setGlobalInputs(array $inputs): void
    {
        foreach ($inputs as $key => $value) {
            $this->setVariable('start', $key, $value);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->store;
    }

    public static function fromArray(array $store): self
    {
        $self = new self();
        $self->store = $store;
        return $self;
    }
}

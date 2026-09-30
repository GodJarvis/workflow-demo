<?php

declare(strict_types=1);

namespace App\Workflow\Document;

/**
 * 运行时节点模型:DSL node 的图视图
 * next/prev 在 Document 构建时由 edges 反向索引得出;port 为出边端口 id(分支标识)
 */
class NodeModel
{
    /**
     * @param string $id 节点 id(DSL 内唯一)
     * @param string $type 节点类型:start/end/code/condition
     * @param array $data DSL 中的 data 原文(inputsValues/script/cases/outputsSchema 等)
     * @param array<int, array{node: string, port: ?string}> $next 后继 [{node, port}]
     * @param string[] $prev 前驱节点 id 集合
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public array $data,
        public array $next = [],
        public array $prev = [],
    ) {
    }

    /**
     * 后继节点 id 列表(去重)
     * @return string[]
     */
    public function nextNodeIds(): array
    {
        $ids = [];
        foreach ($this->next as $edge) {
            if (! in_array($edge['node'], $ids, true)) {
                $ids[] = $edge['node'];
            }
        }
        return $ids;
    }
}

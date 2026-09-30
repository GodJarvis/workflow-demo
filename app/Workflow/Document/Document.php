<?php

declare(strict_types=1);

namespace App\Workflow\Document;

/**
 * 图模型:DSL(json)→ 运行时文档
 *
 * 对应 @flowgram.ai/runtime-js 的 create-store + flat-schema:
 * 将 {nodes, edges} 平铺结构反向索引为 prev/next 邻接表,供调度器 O(1) 查询。
 */
class Document
{
    /** @var array<string, NodeModel> 以 id 为 key 的节点表 */
    private array $nodes = [];

    /** @var array<int, array{from: string, to: string, port: ?string}> 边表 */
    private array $edges = [];

    /** @var array<string, mixed> DSL 原文(id/globalVariables 等) */
    public readonly array $definition;

    private function __construct(array $definition)
    {
        $this->definition = $definition;
    }

    public static function fromArray(array $dsl): self
    {
        $doc = new self($dsl);

        foreach ($dsl['nodes'] ?? [] as $node) {
            $doc->nodes[$node['id']] = new NodeModel(
                id: $node['id'],
                type: $node['type'],
                data: $node['data'] ?? [],
            );
        }

        foreach ($dsl['edges'] ?? [] as $edge) {
            $from = $edge['from'];
            $to = $edge['to'];
            $port = $edge['fromPort'] ?? null;
            $doc->edges[] = ['from' => $from, 'to' => $to, 'port' => $port];

            $fromNode = $doc->nodes[$from] ?? null;
            $toNode = $doc->nodes[$to] ?? null;
            // 悬挂边跳过索引,由 validate() 报错
            if ($fromNode === null || $toNode === null) {
                continue;
            }
            $fromNode->next[] = ['node' => $to, 'port' => $port];
            if (! in_array($from, $toNode->prev, true)) {
                $toNode->prev[] = $from;
            }
        }

        return $doc;
    }

    public static function fromJsonFile(string $path): self
    {
        if (! is_file($path)) {
            throw new \RuntimeException("Workflow DSL file not found: {$path}");
        }
        $dsl = json_decode((string) file_get_contents($path), true);
        if (! is_array($dsl)) {
            throw new \RuntimeException("Workflow DSL is not valid JSON: {$path}");
        }
        return self::fromArray($dsl);
    }

    public function getNode(string $id): ?NodeModel
    {
        return $this->nodes[$id] ?? null;
    }

    /** @return NodeModel[] */
    public function allNodes(): array
    {
        return array_values($this->nodes);
    }

    /** @return string[] */
    public function allNodeIds(): array
    {
        return array_keys($this->nodes);
    }

    public function getStartNode(): NodeModel
    {
        foreach ($this->nodes as $node) {
            if ($node->type === 'start') {
                return $node;
            }
        }
        throw new \RuntimeException('Start node not found in workflow');
    }

    /**
     * 节点后继可达闭包(沿 next 边深度优先收集,含自身)
     * 用于分支裁剪时计算"被跳过分支的子孙集合"
     *
     * @param string $nodeId 起点节点 id
     * @return array<string, true> 可达节点 id 集合
     */
    public function reachableClosure(string $nodeId): array
    {
        $visited = [];
        $stack = [$nodeId];
        while ($stack !== []) {
            $current = array_pop($stack);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            foreach ($this->nodes[$current]->nextNodeIds() as $nextId) {
                $stack[] = $nextId;
            }
        }
        return $visited;
    }

    /**
     * DSL 静态校验:环检测 / 起止节点唯一且存在 / 边端点存在 / 分支边必须声明 port
     * @return string[] 错误列表,空数组 = 通过
     */
    public function validate(): array
    {
        $errors = [];
        $startCount = 0;
        $endCount = 0;

        foreach ($this->nodes as $node) {
            if ($node->type === 'start') {
                $startCount++;
            }
            if ($node->type === 'end') {
                $endCount++;
            }
        }
        if ($startCount !== 1) {
            $errors[] = "Workflow must have exactly one start node, got {$startCount}";
        }
        if ($endCount < 1) {
            $errors[] = 'Workflow must have at least one end node';
        }

        foreach ($this->edges as $edge) {
            if (! isset($this->nodes[$edge['from']]) || ! isset($this->nodes[$edge['to']])) {
                $errors[] = "Edge {$edge['from']} -> {$edge['to']} references missing node";
            }
        }

        // 条件节点:出边必须带 port,且与 cases 中声明的 branch 对应
        foreach ($this->nodes as $node) {
            if ($node->type !== 'condition') {
                continue;
            }
            $declared = array_column($node->data['cases'] ?? [], 'branch');
            foreach ($node->next as $edge) {
                if ($edge['port'] === null) {
                    $errors[] = "Condition node {$node->id} outgoing edge must declare fromPort";
                } elseif (! in_array($edge['port'], $declared, true)) {
                    $errors[] = "Condition node {$node->id} edge port '{$edge['port']}' not declared in cases";
                }
            }
        }

        // 环检测:从 start 出发 DFS 三色标记,只报"回边"(start 可达的环才会真正影响执行)
        $color = [];
        $dfs = function (string $id) use (&$dfs, &$color, &$errors): void {
            $color[$id] = 'gray';
            foreach ($this->nodes[$id]->nextNodeIds() as $nextId) {
                if (($color[$nextId] ?? 'white') === 'gray') {
                    $errors[] = "Cycle detected on edge {$id} -> {$nextId}";
                    continue;
                }
                if (($color[$nextId] ?? 'white') === 'white') {
                    $dfs($nextId);
                }
            }
            $color[$id] = 'black';
        };
        if ($startCount === 1) {
            $dfs($this->getStartNode()->id);
        }

        return $errors;
    }
}

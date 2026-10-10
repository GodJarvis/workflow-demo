<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

use App\Workflow\Engine\Executors\CodeExecutor;
use App\Workflow\Engine\Executors\ConditionExecutor;
use App\Workflow\Engine\Executors\EndExecutor;
use App\Workflow\Engine\Executors\LLMExecutor;
use App\Workflow\Engine\Executors\StartExecutor;
use App\Workflow\Engine\Executors\VideoExecutor;

/**
 * 执行器注册表:type => executor
 */
class ExecutorRegistry
{
    /** @var array<string, NodeExecutorInterface> */
    private array $executors = [];

    public function __construct(NodeExecutorInterface ...$executors)
    {
        foreach ($executors as $executor) {
            $this->executors[$executor->type()] = $executor;
        }
    }

    public static function default(): self
    {
        return new self(
            new StartExecutor(),
            new EndExecutor(),
            new ConditionExecutor(),
            new CodeExecutor(),
            new LLMExecutor(),     // OpenAI 兼容 Chat(mock 可零依赖教学)
            new VideoExecutor(),   // 长耗时异步范型:协程挂起等待,引擎零改动
        );
    }

    public function get(string $type): NodeExecutorInterface
    {
        return $this->executors[$type] ?? throw new \RuntimeException("No executor registered for node type '{$type}'");
    }
}

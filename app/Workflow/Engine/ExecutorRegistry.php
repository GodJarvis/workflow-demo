<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

use App\Workflow\Engine\Executors\CodeExecutor;
use App\Workflow\Engine\Executors\ConditionExecutor;
use App\Workflow\Engine\Executors\EndExecutor;
use App\Workflow\Engine\Executors\StartExecutor;

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
        );
    }

    public function get(string $type): NodeExecutorInterface
    {
        return $this->executors[$type] ?? throw new \RuntimeException("No executor registered for node type '{$type}'");
    }
}

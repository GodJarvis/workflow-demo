<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * 工作流业务错误码(0 = 成功)
 */
enum WorkflowErrorCode: int
{
    case WORKFLOW_NOT_FOUND = 50001;

    case DSL_INVALID = 50002;

    case TASK_NOT_FOUND = 50003;

    case NODE_EXECUTE_FAILED = 50004;

    case RESUME_NOT_POSSIBLE = 50005;

    public function message(): string
    {
        return match ($this) {
            self::WORKFLOW_NOT_FOUND => 'workflow not found',
            self::DSL_INVALID => 'workflow dsl invalid',
            self::TASK_NOT_FOUND => 'task not found',
            self::NODE_EXECUTE_FAILED => 'workflow node execute failed',
            self::RESUME_NOT_POSSIBLE => 'resume not possible: no checkpoint or already finished',
        };
    }
}

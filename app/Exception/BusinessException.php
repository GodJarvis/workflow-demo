<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * 业务异常:message + WorkflowErrorCode 错误码,由 Controller 统一捕获格式化
 */
class BusinessException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $errorCode)
    {
        parent::__construct($message);
    }

    public function getErrorCode(): int
    {
        return $this->errorCode;
    }
}

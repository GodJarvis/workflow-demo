<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\WorkflowErrorCode;
use App\Exception\BusinessException;
use App\Workflow\Application\WorkflowApplication;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * 工作流 HTTP 入口(与 CLI 共用 WorkflowApplication)
 */
class WorkflowController
{
    /**
     * POST /workflow/report — 查询任务执行报告(逐节点 inputs/outputs/logs/耗时)
     */
    public function report(RequestInterface $request): array
    {
        return $this->wrap(function () use ($request) {
            $taskID = $this->required($request, 'taskID');
            return $this->app()->report($taskID);
        });
    }

    /**
     * POST /workflow/run — 提交 DSL 路径 + 输入,同步执行工作流(路由见 config/routes.php)
     */
    public function run(RequestInterface $request): array
    {
        return $this->wrap(function () use ($request) {
            $workflow = (string) $this->required($request, 'workflow');
            $workflowFile = BASE_PATH . '/examples/' . basename($workflow, '.json') . '.json';
            if (! is_file($workflowFile)) {
                throw new BusinessException("workflow file not found: {$workflow}", WorkflowErrorCode::WORKFLOW_NOT_FOUND->value);
            }
            $inputs = $request->input('inputs', []);
            if (is_string($inputs)) {
                $inputs = json_decode($inputs, true) ?: [];
            }
            return $this->app()->run($workflowFile, $inputs);
        });
    }

    /**
     * POST /workflow/validate — 校验 DSL(环/起止节点/边引用/分支端口)
     */
    public function validate(RequestInterface $request): array
    {
        return $this->wrap(function () use ($request) {
            $workflow = (string) $this->required($request, 'workflow');
            $workflowFile = BASE_PATH . '/examples/' . basename($workflow, '.json') . '.json';
            if (! is_file($workflowFile)) {
                throw new BusinessException("workflow file not found: {$workflow}", WorkflowErrorCode::WORKFLOW_NOT_FOUND->value);
            }
            return $this->app()->validate($workflowFile);
        });
    }

    /**
     * POST /workflow/resume — 从最后 checkpoint 恢复执行
     */
    public function resume(RequestInterface $request): array
    {
        return $this->wrap(function () use ($request) {
            $taskID = $this->required($request, 'taskID');
            return $this->app()->resume($taskID);
        });
    }

    // ---------------------------------------------------------------- 内部方法

    private function app(): WorkflowApplication
    {
        return ApplicationContext::getContainer()->get(WorkflowApplication::class);
    }

    private function required(RequestInterface $request, string $key): mixed
    {
        $value = $request->input($key);
        if ($value === null || $value === '') {
            throw new BusinessException("parameter '{$key}' is required", WorkflowErrorCode::DSL_INVALID->value);
        }
        return $value;
    }

    /**
     * 统一响应 {status_code, msg, data, extra} + 异常兜底与日志
     */
    private function wrap(callable $handler): array
    {
        try {
            return [
                'status_code' => 0,
                'msg' => 'ok',
                'data' => $handler(),
                'extra' => [],
            ];
        } catch (BusinessException $e) {
            return ['status_code' => $e->getErrorCode(), 'msg' => $e->getMessage(), 'data' => null, 'extra' => []];
        } catch (\Throwable $e) {
            ApplicationContext::getContainer()->get(StdoutLoggerInterface::class)->error(
                'workflow api error: ' . $e->getMessage(),
                ['trace' => mb_substr($e->getTraceAsString(), 0, 2000)],
            );
            return [
                'status_code' => WorkflowErrorCode::NODE_EXECUTE_FAILED->value,
                'msg' => $e->getMessage(),
                'data' => null,
                'extra' => [],
            ];
        }
    }
}

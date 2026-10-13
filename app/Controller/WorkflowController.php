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

    /**
     * GET /workflow/mock-status?taskId=xxx — 模拟外部系统的状态查询接口(教学)
     * 对账器(CallbackReconciler)经 pollUrl 查询此端点;"外部完成"由测试方写文件触发:
     *   echo '{"status":"succeeded","outputs":{"videoUrl":"..."}}' > .runs/<taskID>/mock-external/<taskId>.json
     */
    public function mockStatus(RequestInterface $request, \Hyperf\HttpServer\Contract\ResponseInterface $response)
    {
        $taskId = (string) $request->input('taskId', '');
        $config = ApplicationContext::getContainer()->get(\Hyperf\Contract\ConfigInterface::class);
        $tasks = new \App\Workflow\Task\TaskStore((string) $config->get('workflow.runs_dir', BASE_PATH . '/.runs'));

        $index = $tasks->loadCallbackIndex();
        $entry = $index[$taskId] ?? null;
        if ($entry === null) {
            return $response->withStatus(404)->raw("unknown taskId {$taskId}");
        }
        $taskID = (string) $entry['taskID'];
        $mockFile = $tasks->taskDir($taskID) . '/mock-external/' . $taskId . '.json';
        if (! is_file($mockFile)) {
            return $response->json(['status' => 'processing', 'taskId' => $taskId]);
        }
        $decoded = json_decode((string) file_get_contents($mockFile), true);
        return $response->json(is_array($decoded) ? $decoded : ['status' => 'processing']);
    }

    /**
     * POST /workflow/async-run — 生产形态入口:校验+建 Task+入队,毫秒级返回 taskID
     * 执行由独立消费进程(WorkflowConsumerProcess)池化完成,连接不阻塞;
     * 进度经 GET /workflow/events(SSE)或 POST /workflow/report 查询
     */
    public function asyncRun(RequestInterface $request): array
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
            $taskID = $this->app()->submit($workflowFile, is_array($inputs) ? $inputs : []);
            return ['taskID' => $taskID, 'status' => 'queued', 'events' => "/workflow/events?taskID={$taskID}"];
        });
    }

    /**
     * GET /workflow/events?taskID=xxx — SSE 事件流(任务进度推送)
     * 实现:轮询 task.json/report.json 的变化推增量(500ms;教学注释:生产改 Redis
     * Pub/Sub 广播 → SSE 端点订阅转发,引擎侧零轮询)。任务终态后发送 event:done 并关闭。
     */
    public function events(RequestInterface $request, \Hyperf\HttpServer\Contract\ResponseInterface $response)
    {
        $taskID = (string) $request->input('taskID', '');
        if ($taskID === '') {
            return $response->raw('taskID required')->withStatus(400);
        }
        $tasks = new \App\Workflow\Task\TaskStore(
            \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\ConfigInterface::class)
                ->get('workflow.runs_dir', BASE_PATH . '/.runs')
        );

        $response = $response
            ->withHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->withHeader('Cache-Control', 'no-cache')
            ->withHeader('X-Accel-Buffering', 'no');

        $lastSignature = '';
        $start = microtime(true);
        while (microtime(true) - $start < 300) {   // 最长 5 分钟
            $task = $tasks->get($taskID);
            if ($task === null) {
                $response->write("event: error\ndata: {\"msg\":\"task not found\"}\n\n");
                break;
            }
            // 状态摘要签名:任务状态 + 各节点状态(变化才推送)
            $nodes = [];
            $reportFile = $tasks->reportFile($taskID);
            if (is_file($reportFile)) {
                $report = json_decode((string) file_get_contents($reportFile), true) ?: [];
                foreach ($report['nodes'] ?? [] as $nodeID => $snapshot) {
                    $nodes[$nodeID] = $snapshot['status'] ?? 'pending';
                }
            }
            $signature = md5(json_encode([$task['status'], $nodes]));
            if ($signature !== $lastSignature) {
                $lastSignature = $signature;
                $payload = json_encode([
                    'taskID' => $taskID, 'status' => $task['status'],
                    'nodes' => $nodes, 'ts' => round(microtime(true) * 1000),
                ], JSON_UNESCAPED_UNICODE);
                $response->write("event: progress\ndata: {$payload}\n\n");
            }
            if (in_array($task['status'], ['success', 'failed'], true)) {
                $response->write("event: done\ndata: {\"status\":\"{$task['status']}\"}\n\n");
                break;
            }
            \Swoole\Coroutine::sleep(0.5);
        }
        return $response;
    }

    /**
     * POST /workflow/callback — 外部系统完成事件回调(异步节点闭环入口)
     * 鉴权:X-Callback-Token(config workflow.callback_token)。
     * 处理:落库 + 投递任务队列(毫秒返回);推进由消费进程单点执行
     */
    public function callback(RequestInterface $request): array
    {
        return $this->wrap(function () use ($request) {
            $token = (string) $request->getHeaderLine('X-Callback-Token');
            $expected = (string) ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\ConfigInterface::class)
                ->get('workflow.callback_token', 'demo-token');
            if ($token === '' || ! hash_equals($expected, $token)) {
                throw new BusinessException('invalid or missing X-Callback-Token', WorkflowErrorCode::DSL_INVALID->value);
            }
            $taskId = (string) $this->required($request, 'taskId');
            $outputs = $request->input('outputs', []);
            if (is_string($outputs)) {
                $outputs = json_decode($outputs, true) ?: [];
            }
            return $this->app()->callback($taskId, is_array($outputs) ? $outputs : []);
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

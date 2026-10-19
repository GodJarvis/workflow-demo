<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Executors;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;
use App\Workflow\Engine\NodeExecutorInterface;
use App\Workflow\Engine\Waiter\WaiterRegistryFactory;
use App\Workflow\Task\TaskStore;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * 视频生成节点:长耗时异步任务的引擎范型(教学重点)
 *
 * ── 为什么引擎 executeNode 零改动就能跑"几分钟的生成任务"───────────────
 * 引擎是协程调度:executor 内任何"协程化等待"(Coroutine::sleep / 协程 hook 的
 * curl)都会让出当前协程,worker 继续服务其它工作流;executeNext 的 WaitGroup
 * 只在本工作流的依赖树上等待,不阻塞别人。因此:
 *   单节点耗时 = 挂起等待,不再占用并发容量
 *   多工作流并发 = 各自独立协程组,互不影响
 *
 * ── 两种工作模式 ────────────────────────────────────────────────
 * ① mock 模式(默认,零外部依赖):data 不配 submitUrl 时,Coroutine::sleep
 *    模拟生成耗时(data.mockSeconds,默认 8s)——用于验证"长任务不阻塞引擎"
 * ② 真实协议(data.submitUrl + pollUrl):submit(POST {prompt}) → {taskId},
 *    循环 poll(GET → {status: processing|succeeded|failed, videoUrl})
 *    直到完成/失败/总超时 —— 生产接入任意视频生成 API 只需改这两个端点
 *
 * ── 生产级 durable 说明(demo 未做,见教学手册03§8)────────────────
 * 协程内等待的代价:进程重启则丢失等待中的任务(节点未写 checkpoint,恢复时会重跑
 * → 外部任务重复提交/计费)。生产形态是"任务外置 + waiting 状态 + 定时器轮询 +
 * checkpoint 持久化外部 taskId"——引擎改造点已写入教学手册。
 *
 * DSL:
 *   { "type": "video", "data": {
 *       "mockSeconds": 8,                  // mock 模式模拟耗时
 *       "submitUrl": "...", "pollUrl": "..." // 真实模式端点(pollUrl 用 {taskId} 占位)
 *       "inputsSchema": { "properties": { "prompt": {...} } },
 *       "inputsValues": { "prompt": {"type":"ref","content":["llm","result"]} }
 *   }}
 * 输出:{ taskId, videoUrl, durationSeconds }
 */
class VideoExecutor implements NodeExecutorInterface
{
    private const DEFAULT_POLL_INTERVAL = 2;

    private const DEFAULT_TOTAL_TIMEOUT = 600;

    public function type(): string
    {
        return 'video';
    }

    public function execute(WorkflowContext $context, NodeModel $node, array $inputs): array
    {
        $prompt = (string) ($inputs['prompt'] ?? '');
        if ($prompt === '') {
            throw new \RuntimeException("Video node '{$node->id}': input 'prompt' is required");
        }
        $data = $node->data;

        // ── asyncCallback 模式:回调闭环范型(不再轮询,挂起等唤醒)──
        // 提交外部任务 → 双注册(进程内等待者索引 + 持久化回调索引)→ Channel 挂起;
        // 外部完成回调 POST /workflow/callback:落库 → 快路径 push 本协程续跑 /
        // 等待者已死(重启/跨进程)→ 慢路径 checkpoint 重载推进。详见教学手册 03§7。
        if (! empty($data['asyncCallback'])) {
            return $this->waitForCallback($context, $node, $prompt);
        }

        // ── mock 模式:挂起模拟生成(验证协程异步语义)──
        if (empty($data['submitUrl'])) {
            $seconds = (int) ($data['mockSeconds'] ?? 8);
            Coroutine::sleep($seconds);   // ★协程挂起:不占 CPU,不阻塞 worker 的其它工作流
            $taskId = 'vid_mock_' . bin2hex(random_bytes(4));
            return [
                'outputs' => [
                    'taskId' => $taskId,
                    'videoUrl' => "https://mock.local/videos/{$taskId}.mp4",
                    'durationSeconds' => $seconds,
                ],
                'logs' => [['level' => 'log', 'msg' => "video generated in {$seconds}s (mock)"]],
            ];
        }

        // ── 真实协议:submit → 轮询直到终态 ──
        $submitUrl = (string) $data['submitUrl'];
        $pollUrl = str_replace('{taskId}', '%s', (string) ($data['pollUrl'] ?? ''));
        $interval = (int) ($data['pollInterval'] ?? self::DEFAULT_POLL_INTERVAL);
        $totalTimeout = (int) ($data['totalTimeout'] ?? self::DEFAULT_TOTAL_TIMEOUT);

        $taskId = $this->submit($submitUrl, $prompt, $totalTimeout);
        $elapsed = 0;
        while (true) {
            $status = $this->poll(sprintf($pollUrl, $taskId), $totalTimeout);
            if (($status['status'] ?? '') === 'succeeded') {
                return [
                    'outputs' => [
                        'taskId' => $taskId,
                        'videoUrl' => (string) ($status['videoUrl'] ?? ''),
                        'durationSeconds' => $elapsed,
                    ],
                    'logs' => [['level' => 'log', 'msg' => "video task {$taskId} succeeded in {$elapsed}s"]],
                ];
            }
            if (($status['status'] ?? '') === 'failed') {
                throw new \RuntimeException("Video node '{$node->id}': remote task {$taskId} failed: " . mb_substr(json_encode($status), 0, 300));
            }
            $elapsed += $interval;
            if ($elapsed >= $totalTimeout) {
                throw new \RuntimeException("Video node '{$node->id}': task {$taskId} poll timeout after {$totalTimeout}s");
            }
            Coroutine::sleep($interval);   // 挂起等待下一轮(协程让出)
        }
    }

    /**
     * 回调等待:双注册 + Channel 挂起 + waiting-callback.json 提示文件(用户从这里拿 taskId)
     *
     * @return array{outputs: array<string, mixed>, logs: array}
     */
    private function waitForCallback(WorkflowContext $context, NodeModel $node, string $prompt): array
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $runsDir = (string) $config->get('workflow.runs_dir', BASE_PATH . '/.runs');
        $tasks = new TaskStore($runsDir);

        $timeout = (int) ($node->data['callbackTimeout'] ?? 120);
        $taskId = 'vid_cb_' . bin2hex(random_bytes(4));

        // ② waiting 提示文件:与文件协议同目录,CLI/用户 cat 此文件拿 taskId 发回调
        //    (video 节点不经 CodeExecutor,任务目录需自建)
        $nodeDir = rtrim($runsDir, '/') . "/{$context->taskID}/nodes/{$node->id}";
        if (! is_dir($nodeDir)) {
            mkdir($nodeDir, 0777, true);
        }
        $waitingFile = $nodeDir . '/waiting-callback.json';
        file_put_contents($waitingFile, json_encode([
            'taskId' => $taskId,
            'nodeID' => $node->id,
            'status' => 'waiting_external_callback',
            'hint' => 'curl -X POST :9501/workflow/callback -H "X-Callback-Token: <token>" -d \'{"taskId":"' . $taskId . '","outputs":{"videoUrl":"https://example.com/v.mp4","taskId":"' . $taskId . '"}}\'',
            'createdAt' => microtime(true),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // ③ 持久化 taskId→node 索引(回调定位凭据 + 对账兜底凭据)
        //    pollUrl:外部状态查询句柄(回调丢失时 Reconciler 主动查询;{taskId} 占位)
        //    deadline:回调丢失超时线(now + data.pollTimeout,默认 600s)
        $pollUrl = isset($node->data['pollUrl']) ? (string) $node->data['pollUrl'] : null;
        $deadline = microtime(true) + (float) ($node->data['pollTimeout'] ?? 600);
        $queued = \App\Workflow\Engine\AsyncContext::isQueueMode();
        $tasks->indexCallback($taskId, $context->taskID, $node->id, $pollUrl, $deadline, $queued);
        $context->nodeStatuses[$node->id] = 'waiting';

        // ── 队列模式(异步消费):注册即暂停,释放消费协程 ──
        // 等待回调的任务 = 0 连接 + 0 协程(纯存储态,数量仅受存储容量约束);
        // 回调消息由消费进程 completeExternalTask 从 checkpoint 重载推进
        // (waiting-callback.json 保留:回调方从这里拿 taskId)
        if (\App\Workflow\Engine\AsyncContext::isQueueMode()) {
            throw new \App\Workflow\Engine\TaskDeferredException(
                "node {$node->id} waiting external callback taskId={$taskId}"
            );
        }

        // ── 同步模式:协程挂起等待唤醒(短周期 pop + 任务终态感知)──
        $registry = WaiterRegistryFactory::make();
        $channel = new Channel(1);
        $registry->register($taskId, $channel, $context->taskID, $node->id);

        try {
            // 短周期 pop + 任务终态感知:唤醒可能来自本进程 Registry push(快),
            // 也可能来自消费进程的 checkpoint 重载推进(异步模式下等待者无法被跨进程 push)——
            // 因此每 POP_SLICE 秒醒来检查一次任务状态,发现已被外部完成即读 checkpoint 优雅收敛
            // (executeNode 后续链路幂等:变量重写同值、已执行节点直接跳过,双推进安全)
            $result = false;
            $deadline = microtime(true) + $timeout;
            while (microtime(true) < $deadline) {
                $result = $channel->pop(3);
                if ($result !== false) {
                    break;   // 快路径:被 push 唤醒
                }
                $task = $tasks->get($context->taskID);
                if ($task !== null && in_array($task['status'], ['success', 'failed'], true)) {
                    // 任务已被其它推进方完成(消费进程)→ 从 checkpoint 恢复本节点输出后收敛
                    $checkpoint = $tasks->loadCheckpoint($context->taskID);
                    $nodeOutputs = $checkpoint['variables'][$node->id] ?? [];
                    $result = [];
                    foreach ($nodeOutputs as $key => $var) {
                        $result[$key] = $var['value'] ?? null;
                    }
                    return [
                        'outputs' => $result,
                        'logs' => [['level' => 'log', 'msg' => "video task {$taskId} completed by external advancement (converged from checkpoint)"]],
                    ];
                }
            }
        } finally {
            $registry->remove($taskId);
            @unlink($waitingFile);
        }

        if ($result === false) {
            // 超时 = 快路径无人唤醒(可能等待者被重启/回调打到了别的进程但慢路径已推进)
            // 幂等防重:若回调事实已存在且节点已被慢路径完成,这里抛错即可(report 可查真实状态)
            throw new \RuntimeException("Video node '{$node->id}': callback wait timeout after {$timeout}s (taskId={$taskId}; 若慢路径已推进,请以 workflow report 为准)");
        }

        $outputs = is_array($result) ? $result : ['result' => $result];
        $outputs['taskId'] = $outputs['taskId'] ?? $taskId;

        return [
            'outputs' => $outputs,
            'logs' => [[
                'level' => 'log',
                'msg' => "video task {$taskId} completed via callback: " . ($outputs['videoUrl'] ?? '(no url)'),
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function submit(string $url, string $prompt, int $timeout): string
    {
        $decoded = $this->http($url, true, json_encode(['prompt' => $prompt], JSON_UNESCAPED_UNICODE), $timeout);
        $taskId = $decoded['taskId'] ?? $decoded['task_id'] ?? null;
        if (! is_string($taskId) || $taskId === '') {
            throw new \RuntimeException('Video submit: response missing taskId');
        }
        return $taskId;
    }

    /** @return array<string, mixed> */
    private function poll(string $url, int $timeout): array
    {
        return $this->http($url, false, null, $timeout);
    }

    /** @return array<string, mixed> */
    private function http(string $url, bool $post, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout];
        if ($post) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException("Video api request failed: {$error}");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("Video api http {$httpCode}: " . mb_substr((string) $raw, 0, 300));
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}

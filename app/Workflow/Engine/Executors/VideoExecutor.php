<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Executors;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;
use App\Workflow\Engine\NodeExecutorInterface;
use Swoole\Coroutine;

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

    /** @return array<string, mixed> */
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

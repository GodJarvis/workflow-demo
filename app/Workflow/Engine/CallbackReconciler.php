<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

use App\Workflow\Application\WorkflowApplication;
use App\Workflow\Task\TaskStore;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Swoole\Coroutine;

/**
 * 回调对账器(Reconciliation Loop):回调丢失的兜底自愈
 *
 * 回调是"外部主动告知",天然不可靠(外部系统挂了/网络丢/回调失败)——
 * 对账器定时主动查询外部任务状态,与回调路径收敛到同一推进点:
 *
 *   有 pollUrl → HTTP 查询({taskId} 占位替换):
 *     succeeded → 充当"迟到的回调"推进(handleCallbackMessage,幂等)
 *     failed    → 任务置败
 *     processing / 查询失败 → 继续等(下一轮)
 *   超过 deadline → 任务置败(回调丢失且查询无果)
 *
 * 与回调的并发安全:推进走同一 completeExternalTask(isExecutedNode 幂等);
 * 索引 unindex 双方均"先查后删",重复处理无害。
 * 定时协程在消费进程内启动(见 WorkflowConsumerProcess),间隔 config
 * workflow.reconcile_interval(默认 30s;demo 演示可调短)。
 */
class CallbackReconciler
{
    public static function start(): void
    {
        $interval = (int) ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('workflow.reconcile_interval', 30);

        Coroutine::create(function () use ($interval): void {
            echo sprintf("[reconciler] started, interval=%ds\n", $interval);
            // 错峰:首轮延迟半个间隔,避免与消费协程抢启动
            Coroutine::sleep((int) ($interval / 2));
            while (true) {
                Coroutine::sleep($interval);
                try {
                    self::reconcileOnce();
                } catch (\Throwable $e) {
                    echo '[reconciler] round error: ' . $e->getMessage() . "\n";
                }
            }
        });
    }

    private static function reconcileOnce(): void
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $tasks = new TaskStore((string) $config->get('workflow.runs_dir', BASE_PATH . '/.runs'));
        $application = ApplicationContext::getContainer()->get(WorkflowApplication::class);

        $index = $tasks->loadCallbackIndex();
        if ($index === []) {
            return;
        }
        $now = microtime(true);
        foreach ($index as $taskId => $entry) {
            $taskID = (string) ($entry['taskID'] ?? '');
            $pollUrl = (string) ($entry['pollUrl'] ?? '');
            $deadline = (float) ($entry['deadline'] ?? 0);

            // ① 状态查询兜底(有凭据才查)
            if ($pollUrl !== '') {
                $status = self::poll(str_replace('{taskId}', $taskId, $pollUrl));
                if ($status !== null) {
                    $state = (string) ($status['status'] ?? '');
                    if ($state === 'succeeded') {
                        $outputs = is_array($status['outputs'] ?? null) ? $status['outputs'] : [];
                        echo "[reconciler] {$taskId}: external succeeded (callback lost, self-healed)\n";
                        $application->handleCallbackMessage($taskId, $outputs);
                        continue;
                    }
                    if ($state === 'failed') {
                        $reason = (string) ($status['error'] ?? 'external task failed');
                        echo "[reconciler] {$taskId}: external failed\n";
                        $application->failExternalCallback($taskId, $reason);
                        continue;
                    }
                    // processing:继续等
                }
                // 查询失败(网络/接口异常):不置败,靠 deadline 兜底
            }

            // ② 超时兜底:回调丢失且查询无果,超过 deadline 置败
            if ($deadline > 0 && $now > $deadline) {
                echo "[reconciler] {$taskId}: deadline exceeded (callback lost)\n";
                $application->failExternalCallback($taskId, "external callback lost: no callback and no poll result before deadline");
            }
        }
    }

    /**
     * 查询外部任务状态:GET pollUrl → {status: processing|succeeded|failed, outputs?, error?}
     * @return array<string, mixed>|null null=查询失败(网络等,不区分于 processing)
     */
    private static function poll(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $httpCode !== 200) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

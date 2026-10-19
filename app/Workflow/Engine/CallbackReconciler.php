<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

use App\Workflow\Application\WorkflowApplication;
use App\Workflow\Task\TaskStore;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 回调对账器(Reconciliation Loop):回调丢失的兜底自愈
 *
 * 回调是"外部主动告知",天然不可靠(外部系统挂了/网络丢/回调失败)——
 * 对账器主动查询外部任务状态,与回调路径收敛到同一推进点:
 *
 *   有 pollUrl → HTTP 查询({taskId} 占位替换):
 *     succeeded → 充当"迟到的回调"(投 run 队列 resume,统一执行器推进)
 *     failed    → 任务置败(failExternalCallback,轻:直接执行)
 *     processing / 查询失败 → 继续等(下一轮)
 *   超过 deadline → 任务置败(回调丢失且查询无果)
 *
 * 执行形态(command 定时任务,与执行器进程解耦):
 *   php bin/hyperf.php workflow:reconcile            单轮执行后退出(crontab 调度)
 *   php bin/hyperf.php workflow:reconcile --rounds=0 --interval=10   常驻轮询(演示)
 * 独立 command 的收益:与 RunConsumerProcess 生命周期解耦(执行器重启不影响对账)、
 * 频率由 crontab 独立配置、多实例错峰;推进仍统一投 run 队列(与回调同一收敛点,
 * completeExternalTask 幂等,并发撞车先到先赢)
 */
class CallbackReconciler
{
    /**
     * 执行一轮对账,返回统计
     * @return array{scanned: int, healed: int, failedExternal: int, timedOut: int}
     */
    public static function runOnce(): array
    {
        $stats = ['scanned' => 0, 'healed' => 0, 'failedExternal' => 0, 'timedOut' => 0];

        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $tasks = new TaskStore((string) $config->get('workflow.runs_dir', BASE_PATH . '/.runs'));
        $application = ApplicationContext::getContainer()->get(WorkflowApplication::class);

        $index = $tasks->loadCallbackIndex();
        if ($index === []) {
            return $stats;
        }
        $now = microtime(true);
        foreach ($index as $taskId => $entry) {
            ++$stats['scanned'];
            $pollUrl = (string) ($entry['pollUrl'] ?? '');
            $deadline = (float) ($entry['deadline'] ?? 0);

            // ① 状态查询兜底(有凭据才查)
            if ($pollUrl !== '') {
                $status = self::poll(str_replace('{taskId}', $taskId, $pollUrl));
                if ($status !== null) {
                    $state = (string) ($status['status'] ?? '');
                    if ($state === 'succeeded') {
                        $outputs = is_array($status['outputs'] ?? null) ? $status['outputs'] : [];
                        $application->dispatchResume($taskId, $outputs);   // 充当迟到的回调 → 统一执行器
                        ++$stats['healed'];
                        continue;
                    }
                    if ($state === 'failed') {
                        $reason = (string) ($status['error'] ?? 'external task failed');
                        $application->failExternalCallback($taskId, $reason);
                        ++$stats['failedExternal'];
                        continue;
                    }
                    // processing:继续等
                }
                // 查询失败(网络/接口异常):不置败,靠 deadline 兜底
            }

            // ② 超时兜底:回调丢失且查询无果,超过 deadline 置败
            if ($deadline > 0 && $now > $deadline) {
                $application->failExternalCallback(
                    $taskId,
                    'external callback lost: no callback and no poll result before deadline',
                );
                ++$stats['timedOut'];
            }
        }
        return $stats;
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

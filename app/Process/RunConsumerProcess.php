<?php

declare(strict_types=1);

namespace App\Process;

use App\Workflow\Application\WorkflowApplication;
use App\Workflow\Queue\TaskQueueFactory;
use Hyperf\Process\AbstractProcess;

/**
 * 任务执行消费进程:消费 run 队列(重且长的负载 —— 沙箱/LLM 执行)
 *
 * 唯一执行器(CallbackConsumerProcess 已退役,其路由职责并入 API 侧):
 *   消息:{type:'run', taskID}    → executeTask(全新执行)
 *        {type:'resume', taskId} → completeExternalTask(重载推进,可能跑后继沙箱
 *                                  —— 与 run 同为任意重操作,归同一池公平排队)
 * 同一任务的 run/resume 消息同队列 FIFO → 天然串行,双推进竞态极少发生
 * 对账已解耦:CallbackReconciler 独立为 workflow:reconcile command(crontab 调度)
 */
class RunConsumerProcess extends AbstractProcess
{
    public function getName(): string
    {
        return 'workflow-run-consumer';
    }

    public function handle(): void
    {
        $poolSize = (int) \Hyperf\Context\ApplicationContext::getContainer()
            ->get(\Hyperf\Contract\ConfigInterface::class)
            ->get('workflow.run_pool_size', 4);

        echo sprintf("[run-consumer] starting, pool=%d\n", $poolSize);

        TaskQueueFactory::make('run')->consume(function (array $message): void {
            $type = (string) ($message['type'] ?? '');
            $application = \Hyperf\Context\ApplicationContext::getContainer()->get(WorkflowApplication::class);

            switch ($type) {
                case 'run':
                    $taskID = (string) ($message['taskID'] ?? '');
                    if ($taskID !== '') {
                        echo "[run-consumer] run {$taskID}\n";
                        $application->executeTask($taskID);
                    }
                    break;

                case 'resume':
                    // 统一推进:callback 路由层投递(队列任务唯一路径/同步任务双保险)
                    $externalTaskId = (string) ($message['externalTaskId'] ?? '');
                    if ($externalTaskId !== '') {
                        echo "[run-consumer] resume {$externalTaskId}\n";
                        $outputs = is_array($message['outputs'] ?? null) ? $message['outputs'] : [];
                        $application->resumeExternalTask($externalTaskId, $outputs);
                    }
                    break;
            }
        }, $poolSize);
    }
}

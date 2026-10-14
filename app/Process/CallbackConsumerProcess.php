<?php

declare(strict_types=1);

namespace App\Process;

use App\Workflow\Application\WorkflowApplication;
use App\Workflow\Engine\CallbackReconciler;
use App\Workflow\Queue\TaskQueueFactory;
use Hyperf\Process\AbstractProcess;

/**
 * 回调推进消费进程:消费 callback 队列(轻且快的负载 —— 对响应延迟敏感)
 * 同时承载回调对账器(Reconciler 的本质就是"处理没有到来的回调",归属此进程)
 *
 * 消息:{type:'callback', externalTaskId, outputs}
 *   → handleCallbackMessage:Registry deliver(同步等待者)→ 未命中 checkpoint 重载推进(幂等)
 *
 * 与 RunConsumerProcess 的并发安全:对账推进与回调推进可能并发处理同一 taskId,
 * 由 completeExternalTask 的 isExecutedNode 幂等兜底(先到先赢,后到返回现状)
 */
class CallbackConsumerProcess extends AbstractProcess
{
    public function getName(): string
    {
        return 'workflow-callback-consumer';
    }

    public function handle(): void
    {
        $poolSize = (int) \Hyperf\Context\ApplicationContext::getContainer()
            ->get(\Hyperf\Contract\ConfigInterface::class)
            ->get('workflow.callback_pool_size', 2);

        echo sprintf("[callback-consumer] starting, pool=%d\n", $poolSize);

        // 回调对账器(回调丢失兜底:pollUrl 查询自愈 / deadline 超时置败)
        CallbackReconciler::start();

        TaskQueueFactory::make('callback')->consume(function (array $message): void {
            $externalTaskId = (string) ($message['externalTaskId'] ?? '');
            if (($message['type'] ?? '') === 'callback' && $externalTaskId !== '') {
                echo "[callback-consumer] callback {$externalTaskId}\n";
                $outputs = is_array($message['outputs'] ?? null) ? $message['outputs'] : [];
                \Hyperf\Context\ApplicationContext::getContainer()
                    ->get(WorkflowApplication::class)->handleCallbackMessage($externalTaskId, $outputs);
            }
        }, $poolSize);
    }
}

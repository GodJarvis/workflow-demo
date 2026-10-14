<?php

declare(strict_types=1);

namespace App\Process;

use App\Workflow\Application\WorkflowApplication;
use App\Workflow\Queue\TaskQueueFactory;
use Hyperf\Process\AbstractProcess;

/**
 * 任务执行消费进程:消费 run 队列(重且长的负载 —— 沙箱/LLM 执行)
 *
 * 与 CallbackConsumerProcess 拆分的理由(独立队列独立进程独立池):
 *   ① 回调延迟不被长任务拖累(混池时池满 → 回调排队)
 *   ② 独立扩缩容:run 池按负载扩(run_pool_size),callback 池恒定小池
 *   ③ 故障隔离:沙箱执行异常不影响回调推进
 *   注意:Redis Stream 同组竞争会把 run 消息分给回调进程,必须物理分队列
 *
 * 消息:{type:'run', taskID} → executeTask(AsyncContext 队列模式:deferred 暂停释放协程)
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
            $taskID = (string) ($message['taskID'] ?? '');
            if (($message['type'] ?? '') === 'run' && $taskID !== '') {
                echo "[run-consumer] run {$taskID}\n";
                \Hyperf\Context\ApplicationContext::getContainer()
                    ->get(WorkflowApplication::class)->executeTask($taskID);
            }
        }, $poolSize);
    }
}

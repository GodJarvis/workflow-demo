<?php

declare(strict_types=1);

namespace App\Process;

use App\Workflow\Application\WorkflowApplication;
use App\Workflow\Queue\TaskQueueFactory;
use Hyperf\Process\AbstractProcess;

/**
 * 工作流消费进程:批量任务的异步执行器(与 HTTP worker 生命周期隔离)
 *
 * 架构位置:
 *   POST /workflow/async-run → 校验+建 Task(queued)→ 入队 → 202 {taskID}(连接毫秒级释放)
 *   本进程(常驻)→ 池内消费协程竞争拉取 → 引擎执行 / 回调推进
 *
 * 消息处理:
 *   {type:'run'}      → executeTask:加载 DSL+inputs → Engine::process(引擎零改动)
 *   {type:'callback'} → 先 Registry deliver(同步模式等待者即时唤醒,若同进程可命中)
 *                      未命中 → completeExternalTask(checkpoint 重载推进,幂等)
 *                      单点决策消灭双推进竞态(原 3s 延迟复查协程退役)
 *
 * 池大小 = 并发上限(削峰):config workflow.consumer_pool_size
 * 注册方式:config/autoload/processes.php 配置式(不依赖注解扫描——@Listener 注解
 * 在本环境不生效的教训,见踩坑实录 17)
 */
class WorkflowConsumerProcess extends AbstractProcess
{
    public function getName(): string
    {
        return 'workflow-consumer';
    }

    public function handle(): void
    {
        $poolSize = (int) (\Hyperf\Support\env(
            'CONSUMER_POOL_SIZE',
            (string) \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Contract\ConfigInterface::class)
                ->get('workflow.consumer_pool_size', 4),
        ));

        echo sprintf("[workflow-consumer] starting, pool=%d\n", $poolSize);

        TaskQueueFactory::make()->consume(function (array $message): void {
            $type = (string) ($message['type'] ?? '');
            $application = \Hyperf\Context\ApplicationContext::getContainer()->get(WorkflowApplication::class);

            switch ($type) {
                case 'run':
                    $taskID = (string) ($message['taskID'] ?? '');
                    if ($taskID !== '') {
                        echo "[workflow-consumer] run {$taskID}\n";
                        $application->executeTask($taskID);
                    }
                    break;

                case 'callback':
                    $externalTaskId = (string) ($message['externalTaskId'] ?? '');
                    $outputs = is_array($message['outputs'] ?? null) ? $message['outputs'] : [];
                    if ($externalTaskId !== '') {
                        echo "[workflow-consumer] callback {$externalTaskId}\n";
                        $application->handleCallbackMessage($externalTaskId, $outputs);
                    }
                    break;
            }
        }, $poolSize);
    }
}

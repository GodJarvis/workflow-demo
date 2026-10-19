<?php

declare(strict_types=1);

namespace App\Command;

use App\Workflow\Engine\CallbackReconciler;
use Hyperf\Command\Command as HyperfCommand;
use Swoole\Coroutine;
use Symfony\Component\Console\Input\InputOption;

/**
 * 回调对账定时任务(与执行器进程解耦,独立调度):
 *
 *   单轮模式(默认,跑完退出,由 crontab 调度):
 *     php bin/hyperf.php workflow:reconcile
 *     crontab 示例(宿主,每分钟):
 *       * * * * * docker exec wf-engine sh -c "cd /var/project/workflow-demo && php bin/hyperf.php workflow:reconcile"
 *
 *   常驻模式(演示/无 crontab 环境;--rounds=0 表示无限轮):
 *     php bin/hyperf.php workflow:reconcile --rounds=0 --interval=10
 *
 * 收益:对账频率独立于执行器配置、执行器重启/扩缩容不影响对账、多实例 crontab 错峰
 */
#[Command]
class ReconcileCommand extends HyperfCommand
{
    protected function configure(): void
    {
        $this->setName('workflow:reconcile')
            ->setDescription('Reconcile pending external callbacks (poll self-heal / deadline fail)')
            ->addOption('rounds', null, InputOption::VALUE_REQUIRED, '执行轮数(0=无限;默认 1 单轮后退出)', '1')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, '常驻模式轮间隔秒(默认取 config reconcile_interval)', (string) null);
    }

    public function handle(): void
    {
        $rounds = max(0, (int) $this->input->getOption('rounds'));
        $interval = (int) ($this->input->getOption('interval')
            ?? \Hyperf\Support\env('RECONCILE_INTERVAL',
                (string) \Hyperf\Context\ApplicationContext::getContainer()
                    ->get(\Hyperf\Contract\ConfigInterface::class)
                    ->get('workflow.reconcile_interval', 30)));

        $executed = 0;
        while ($rounds === 0 || $executed < $rounds) {
            ++$executed;
            $stats = CallbackReconciler::runOnce();
            $this->line(sprintf(
                '[reconcile] round %d: scanned=%d healed=%d externalFailed=%d timedOut=%d',
                $executed,
                $stats['scanned'],
                $stats['healed'],
                $stats['failedExternal'],
                $stats['timedOut'],
            ));
            if ($rounds !== 0 && $executed >= $rounds) {
                break;
            }
            Coroutine::sleep($interval);
        }
    }
}

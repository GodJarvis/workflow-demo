<?php

declare(strict_types=1);

namespace App\Command;

use App\Workflow\Application\WorkflowApplication;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Context\ApplicationContext;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * 工作流 CLI 入口(与 HTTP 共用 WorkflowApplication)
 *
 *   php bin/hyperf.php workflow:run examples/first-flow.json --inputs='{"query":"hello workflow"}'
 *   php bin/hyperf.php workflow:report <taskID>
 *   php bin/hyperf.php workflow:validate examples/first-flow.json
 *   php bin/hyperf.php workflow:resume <taskID>
 */
#[Command]
class WorkflowCommand extends HyperfCommand
{
    protected function configure(): void
    {
        $this->setName('workflow')
            ->setDescription('Workflow engine demo commands')
            ->addArgument('action', InputArgument::REQUIRED, 'run | report | validate | resume')
            ->addArgument('target', InputArgument::OPTIONAL, 'DSL file path or taskID')
            ->addOption('inputs', 'i', InputOption::VALUE_REQUIRED, 'JSON inputs for run, e.g. \'{"query":"hi"}\'');
    }

    public function handle(): void
    {
        $action = (string) $this->input->getArgument('action');
        $target = (string) ($this->input->getArgument('target') ?? '');

        $app = ApplicationContext::getContainer()->get(WorkflowApplication::class);

        match ($action) {
            'run' => $this->doRun($app, $target),
            'report' => $this->dumpJson($app->report($target)),
            'validate' => $this->dumpJson($app->validate($target)),
            'resume' => $this->dumpResult($app->resume($target)),
            default => $this->error("Unknown action '{$action}', expect run|report|validate|resume"),
        };
    }

    private function doRun(WorkflowApplication $app, string $file): void
    {
        if ($file === '' || ! is_file($file)) {
            $this->error("DSL file not found: {$file}");
            return;
        }
        $inputs = [];
        $raw = $this->input->getOption('inputs');
        if (is_string($raw) && $raw !== '') {
            $inputs = json_decode($raw, true);
            if (! is_array($inputs)) {
                $this->error('--inputs is not valid JSON');
                return;
            }
        }

        $result = $app->run($file, $inputs);
        $this->dumpResult($result);
    }

    /**
     * 打印执行结果:逐节点状态摘要 + 最终输出
     */
    private function dumpResult(array $result): void
    {
        $icon = match ($result['status']) {
            'success' => '✓',
            'failed' => '✗',
            default => '…',
        };
        $this->line("");
        $this->info("▶ Task {$result['taskID']}  {$icon} {$result['status']}  ({$result['timeCost']})");

        foreach ($result['report']['nodes'] ?? [] as $nodeID => $snapshot) {
            $statusIcon = match ($snapshot['status'] ?? '') {
                'success' => '✓',
                'failed' => '✗',
                'skipped' => '·',
                'processing' => '…',
                default => ' ',
            };
            $cost = $snapshot['timeCost'] ?? '-';
            $branch = isset($snapshot['branch']) && $snapshot['branch'] ? " → branch:{$snapshot['branch']}" : '';
            $this->line(sprintf('  %s [%-14s] %s %6s%s', $statusIcon, substr($nodeID, 0, 14), $nodeID, $cost, $branch));
            if (($snapshot['error'] ?? null) !== null) {
                $this->error('      error: ' . $snapshot['error']);
            }
            foreach ($snapshot['logs'] ?? [] as $log) {
                $this->line("      log({$log['level']}): {$log['msg']}");
            }
        }

        if ($result['status'] === 'success') {
            $this->info('▶ Outputs: ' . json_encode($result['outputs'], JSON_UNESCAPED_UNICODE));
        } else {
            $this->error('▶ Error: ' . ($result['error'] ?? 'unknown'));
        }
    }

    private function dumpJson(array $data): void
    {
        $this->line(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

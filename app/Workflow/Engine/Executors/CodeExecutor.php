<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Executors;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;
use App\Workflow\Engine\NodeExecutorInterface;
use App\Workflow\Sandbox\SandboxDriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 代码节点:多语言沙箱执行
 *
 * 协议与 Coze/FlowGram/Dify 一致:用户代码提供 main 入口,入参 params、返回值即 outputs:
 *   python:     def main(params: dict) -> dict
 *   javascript: async function main({ params }) { return {...}; }
 *   php:        function main(array $params): array
 *
 * 数据在引擎 ↔ 沙箱间以 JSON 文件过边界(/task 或任务目录下 input.json → output.json),
 * 用户代码拿不到引擎进程的任何全局状态(隔离边界即数据边界)。
 * 沙箱档位:docker(一次性容器,默认)/ process(dify 风格进程级,可节点级覆盖)。
 * 沙箱 stderr 按 JSON Lines 输出日志,解析后写入节点快照。
 * 代码节点默认不重试(副作用不可知)。
 */
class CodeExecutor implements NodeExecutorInterface
{
    /** 语言 → 代码文件名(文件协议约定,两档沙箱共用) */
    private const FILE_NAMES = [
        'python' => 'code.py',
        'javascript' => 'code.js',
        'php' => 'code.php',
    ];

    private string $runsDir;

    /** @var array<string, string> 节点级沙箱档位白名单(防 DSL 随意指定驱动名) */
    private const DRIVERS = ['docker', 'process'];

    public function __construct()
    {
        $this->runsDir = ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('workflow.runs_dir', BASE_PATH . '/.runs');
    }

    public function type(): string
    {
        return 'code';
    }

    public function execute(WorkflowContext $context, NodeModel $node, array $inputs): array
    {
        $script = $node->data['script'] ?? [];
        $language = (string) ($script['language'] ?? '');
        $content = (string) ($script['content'] ?? '');

        if ($content === '') {
            throw new \RuntimeException("Code node '{$node->id}': script content is required");
        }
        if (! isset(self::FILE_NAMES[$language])) {
            throw new \RuntimeException("Code node '{$node->id}': unsupported language '{$language}' (supported: " . implode('/', array_keys(self::FILE_NAMES)) . ')');
        }

        // 1. 落任务文件:code.* + input.json(文件协议的写入侧)
        $taskDir = rtrim($this->runsDir, '/') . "/{$context->taskID}/nodes/{$node->id}";
        if (! is_dir($taskDir)) {
            mkdir($taskDir, 0777, true);
        }
        $fileName = self::FILE_NAMES[$language];
        file_put_contents($taskDir . '/' . $fileName, $content);
        file_put_contents($taskDir . '/input.json', json_encode(['params' => $inputs], JSON_UNESCAPED_UNICODE));
        // 沙箱内以低权限用户执行(docker 档 nobody / process 档池化 UID),需放开读写权限
        chmod($taskDir . '/' . $fileName, 0666);
        chmod($taskDir . '/input.json', 0666);
        chmod($taskDir, 0777);

        // 2. 选择沙箱档位:节点级 data.sandboxDriver 优先,回落全局配置
        $driverName = $node->data['sandboxDriver'] ?? null;
        if (is_string($driverName) && ! in_array($driverName, self::DRIVERS, true)) {
            throw new \RuntimeException("Code node '{$node->id}': unknown sandboxDriver '{$driverName}'");
        }
        $driver = SandboxDriverFactory::make($driverName);
        $result = $driver->run($taskDir, $language);

        // 3. 退出码语义映射:0 成功 / 124 超时 / 137 OOM / 其他 运行时错误
        if ($result['exitCode'] !== 0) {
            throw new \RuntimeException(sprintf(
                "Code node '%s' sandbox(%s) exit=%d (%s) stderr: %s",
                $node->id,
                $driverName ?? 'default',
                $result['exitCode'],
                match ($result['exitCode']) {
                    124 => 'timeout',
                    137 => 'OOM-killed',
                    default => 'runtime error',
                },
                mb_substr($result['stderr'], -1000),
            ));
        }

        // 4. 读取输出(JSON 边界的读取侧)
        $outputFile = $taskDir . '/output.json';
        if (! is_file($outputFile)) {
            throw new \RuntimeException("Code node '{$node->id}': sandbox produced no output.json");
        }
        $outputs = json_decode((string) file_get_contents($outputFile), true);
        if (! is_array($outputs) || ! is_array($outputs['ret'] ?? null)) {
            throw new \RuntimeException("Code node '{$node->id}': invalid output.json (expect {\"ret\": {...}})");
        }

        // 5. stderr JSON Lines 解析为节点日志
        return [
            'outputs' => $outputs['ret'],
            'logs' => $this->parseLogs($result['stderr']),
        ];
    }

    /**
     * 沙箱日志协议:每行一个 {"level":"log|info|warn|error","msg":"..."}
     * @return array<int, array{level: string, msg: string}>
     */
    private function parseLogs(string $stderr): array
    {
        $logs = [];
        foreach (explode("\n", trim($stderr)) as $line) {
            if ($line === '' || str_starts_with($line, '[')) {
                continue; // 跳过空行与 [sandbox finished in ...] 元信息
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['msg'])) {
                $logs[] = ['level' => (string) ($decoded['level'] ?? 'log'), 'msg' => (string) $decoded['msg']];
            }
        }
        return $logs;
    }
}

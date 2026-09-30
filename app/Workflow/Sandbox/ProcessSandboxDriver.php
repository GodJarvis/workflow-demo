<?php

declare(strict_types=1);

namespace App\Workflow\Sandbox;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 档1 沙箱:进程级(教学移植自 dify-sandbox 的隔离思路)
 *
 * dify 原版(Go, internal/core/lib/python/add_seccomp.go)的锁序列:
 *   chroot(".") → SetNoNewPrivs → seccomp 白名单 BPF(默认 KILL + TSYNC)
 *   → setgroups → setgid → setuid(池化 UID)→ exec 用户代码
 *
 * 本 PHP 版对应实现与【刻意保留的差距】(教学要点):
 *   ✅ 降权 + 防提权  setpriv --reuid/--regid/--clear-groups --no-new-privs
 *      (对应 setuid 序列 + no_new_privs;setpriv 来自 util-linux)
 *   ✅ UID 池隔离     UidPool(Swoole Channel ≡ dify 的 Go chan 池)
 *   ✅ 资源限制       ulimit -v(虚拟内存)/-t(CPU 秒)/-u(进程数)
 *      —— 这一点比 dify 原版更严:dify 未做内存硬限制,是它的已知弱项
 *   ✅ 超时强杀       Symfony Process timeout(SIGKILL)
 *   ✅ env 白名单     只透传 allowed_env_vars(对应 dify conf 的 allowed_env_vars)
 *   ❌ 无 seccomp 白名单:seccomp BPF 只能由进程"自己给自己装"(dify 为此用 Go 编译
 *      c-shared python.so,在用户代码进程内 CDLL 加载装锁,装完才 exec 用户代码),
 *      PHP 无等价机制。所以本档的用户代码理论上可调用任意 syscall。
 *   ❌ 无 chroot:chroot 后解释器自身的模块路径不可见,需要像 dify 那样在镜像层
 *      精心准备运行时目录;教学版用"降权用户 + 目录权限"近似(65534 只能写任务目录)。
 *   ❌ 无禁网:进程级无 network namespace;容器档用 NetworkMode:none 硬禁。
 *      生产建议:开放多租户一律走 docker 档,或像 dify 一样前置代理管控出网。
 *
 * 冷启动 ~30-80ms(docker 档 ~150-300ms)—— 两档的取舍就在这里。
 *
 * @see SandboxDriverInterface
 * @see UidPool
 */
class ProcessSandboxDriver implements SandboxDriverInterface
{
    /** 语言 → 代码文件名(与文件协议约定一致) */
    private const FILE_NAMES = [
        'python' => 'code.py',
        'javascript' => 'code.js',
        'php' => 'code.php',
    ];

    /** 语言 → 解释器命令(process 档要求解释器在引擎容器内可用) */
    private const INTERPRETERS = [
        'python' => 'python3',
        'javascript' => 'node',
        'php' => 'php',
    ];

    /**
     * 语言 → ulimit(以 KB 计的 -v 虚拟内存上限)
     * 注意:Node/V8 启动即预留 >1.5G 虚拟地址空间,-v 不能照搬容器的 256m,
     * 否则解释器自身先崩(进程级沙箱的经典坑:限制的是地址空间而非 RSS)
     */
    private const ULIMIT_VIRTUAL_KB = [
        'python' => 524288,      // 512M
        'javascript' => 2097152, // 2G(V8 预留)
        'php' => 524288,         // 512M
    ];

    private array $processConfig;

    /** @var string[] */
    private array $allowedEnvVars;

    public function __construct(private readonly UidPool $uidPool)
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $this->processConfig = $config->get('workflow.sandbox_process', []);
        $this->allowedEnvVars = $config->get('workflow.sandbox.allowed_env_vars', ['PATH', 'LANG']);
    }

    public function run(string $taskDir, string $language): array
    {
        $interpreter = self::INTERPRETERS[$language]
            ?? throw new \RuntimeException("Process sandbox: unsupported language '{$language}'");
        $fileName = self::FILE_NAMES[$language];
        $timeout = (int) ($this->processConfig['timeout'] ?? 15);

        // ① 借 UID(池空阻塞;dify 同款语义)
        $uid = $this->uidPool->acquire();
        $workDir = null;

        try {
            // ② 一次性私有工作目录(dify 同构:InitializeEnvironment + AfterExitHook 清理)。
            //    必要性(真实踩坑):同一目录被不同 UID 先后使用时,Python __pycache__ 等
            //    残留产物属主是上一个 UID,会让本次执行 PermissionError —— 每次执行
            //    独立目录后 UID 轮换互不干扰,这正是 UID 池成立的前提
            $workDir = $taskDir . '/.proc_' . $uid . '_' . bin2hex(random_bytes(3));
            if (! is_dir($workDir)) {
                mkdir($workDir, 0777, true);
            }
            // mkdir 受进程 umask 削权(022 → 0755),必须显式 chmod 才能让降权 UID 可写
            chmod($workDir, 0777);
            foreach ([$fileName, 'input.json'] as $copyFile) {
                copy($taskDir . '/' . $copyFile, $workDir . '/' . $copyFile);
                chmod($workDir . '/' . $copyFile, 0666);
            }

            // ③ 组装:setpriv 降权 + no-new-privs,内层 sh 套 ulimit 后 exec 解释器
            //    runner 模板复用 docker 档同一份(文件协议不变,只有执行方式不同)
            $runnerDir = BASE_PATH . '/docker/runners';
            $runnerScript = match ($language) {
                'python' => $runnerDir . '/runner.py',
                'javascript' => $runnerDir . '/runner.mjs',
                'php' => $runnerDir . '/runner.php',
            };
            $virtualKb = (int) ($this->processConfig['ulimit_virtual_kb'][$language] ?? self::ULIMIT_VIRTUAL_KB[$language]);
            $cpuSeconds = (int) ($this->processConfig['ulimit_cpu_seconds'] ?? 10);
            $maxProcesses = (int) ($this->processConfig['ulimit_max_processes'] ?? 64);

            $inner = sprintf(
                'ulimit -v %d -t %d -u %d; exec %s %s %s %s %s',
                $virtualKb,
                $cpuSeconds,
                $maxProcesses,
                escapeshellcmd($interpreter),
                escapeshellarg($runnerScript),
                escapeshellarg($workDir . '/' . $fileName),
                escapeshellarg($workDir . '/input.json'),
                escapeshellarg($workDir . '/output.json'),
            );

            // 命令组装:env -i 白名单环境(继承进程 env 会泄漏宿主敏感变量)
            // → busybox timeout 兜底 wall-clock 超时(超时 exit=124,与退出码协议对齐)
            // → setpriv 降权 → cd 工作目录(Coroutine::exec 无 cwd 参数)
            $env = $this->buildEnv();
            $envPrefix = implode(' ', array_map(
                fn (string $k, string $v): string => escapeshellarg("{$k}={$v}"),
                array_keys($env),
                array_values($env),
            ));
            $command = sprintf(
                'env -i %s timeout %d setpriv --reuid=%d --regid=%d --clear-groups --no-new-privs /bin/sh -c %s > %s 2> %s',
                $envPrefix,
                $timeout,
                $uid,
                $uid,
                escapeshellarg('cd ' . escapeshellarg($workDir) . ' && ' . $inner),
                escapeshellarg($workDir . '/stdout.log'),
                escapeshellarg($workDir . '/stderr.log'),
            );

            $start = microtime(true);
            // Swoole 原生协程 exec:等待期间协程挂起(runtime 调度),不走 Symfony Process
            // 的 1ms 级轮询 —— 后者在 hyperf server 的 EventLoop 里轮询粒度被放大到
            // ~200ms(实测教训:同代码 CLI 38ms / HTTP 200ms 的元凶)
            [$exitCode] = \Swoole\Coroutine::exec($command);
            $stdout = (string) @file_get_contents($workDir . '/stdout.log');
            $stderrOutput = (string) @file_get_contents($workDir . '/stderr.log');

            // ④ 成果回传主目录(output.json 是引擎与上层 CodeExecutor 的读取契约)
            if (is_file($workDir . '/output.json')) {
                copy($workDir . '/output.json', $taskDir . '/output.json');
                chmod($taskDir . '/output.json', 0666);
            }

            return [
                'exitCode' => (int) $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderrOutput
                    . sprintf("\n[sandbox finished in %dms, uid=%d, exit=%d]", (microtime(true) - $start) * 1000, $uid, $exitCode),
            ];
        } finally {
            // ⑤ 归还 UID + 销毁一次性工作目录(AfterExitHook 语义)
            if ($workDir !== null && is_dir($workDir)) {
                // 引擎进程以 root 运行,可无视属主清理降权用户留下的目录
                foreach (new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($workDir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                ) as $item) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
                rmdir($workDir);
            }
            $this->uidPool->release($uid);
        }
    }

    /**
     * 环境变量白名单:只透传白名单变量,杜绝宿主敏感信息(密钥/DSN)泄入用户代码
     * @return array<string, string>
     */
    private function buildEnv(): array
    {
        $env = [];
        foreach ($this->allowedEnvVars as $name) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $env[$name] = $value;
            }
        }
        if (! isset($env['PATH'])) {
            $env['PATH'] = '/usr/local/bin:/usr/bin:/bin';
        }
        return $env;
    }
}

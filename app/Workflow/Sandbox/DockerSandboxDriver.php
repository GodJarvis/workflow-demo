<?php

declare(strict_types=1);

namespace App\Workflow\Sandbox;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Symfony\Component\Process\Process;

/**
 * 档2 沙箱:一次性容器(Docker Engine API 直调,不依赖 docker CLI)
 *
 * 每次执行 = create(限额/禁网/只读/seccomp/非root)→ start → wait → logs → delete,
 * 隔离强度最高(独立 namespace/cgroup),冷启动 ~150-300ms。
 *
 * ⚠️ docker.sock 背后是【宿主机 daemon】,Binds 挂载与 seccomp 内联 JSON 为 daemon 侧语义,
 * 挂载路径必须换算为宿主机路径(config workflow.path_map)。
 *
 * @see SandboxDriverInterface
 */
class DockerSandboxDriver implements SandboxDriverInterface
{
    private const DOCKER_SOCKET = '/var/run/docker.sock';

    /** 语言 → 沙箱内代码文件名(与 CodeExecutor 的文件协议约定一致) */
    private const FILE_NAMES = [
        'python' => 'code.py',
        'javascript' => 'code.js',
        'php' => 'code.php',
    ];

    /** @var array<string, string> */
    private array $pathMap;

    private array $sandboxConfig;

    /** @var array<string, string> */
    private array $images;

    /** @var string[] */
    private array $allowedEnvVars;

    public function __construct()
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $this->pathMap = $config->get('workflow.path_map', []);
        $this->sandboxConfig = $config->get('workflow.sandbox', []);
        $this->images = $config->get('workflow.sandbox_images', []);
        // 借鉴 dify-sandbox conf: allowed_env_vars —— 只有白名单内的宿主环境变量才透传进沙箱
        $this->allowedEnvVars = $config->get('workflow.sandbox.allowed_env_vars', ['PATH', 'LANG']);
    }

    public function run(string $taskDir, string $language): array
    {
        $image = $this->images[$language] ?? throw new \RuntimeException("No sandbox image configured for language '{$language}'");
        $fileName = self::FILE_NAMES[$language];
        $containerName = 'wf_' . uniqid('sandbox_');

        $containerId = $this->createContainer($containerName, $taskDir, $image, $fileName);
        if ($containerId === null) {
            return ['exitCode' => 125, 'stdout' => '', 'stderr' => 'docker create failed'];
        }

        [$startOk, $startBody] = $this->dockerRequest('POST', "/containers/{$containerId}/start", timeout: 30);
        if (! $startOk) {
            $this->dockerRequest('DELETE', "/containers/{$containerId}?force=1&v=1", timeout: 10);
            $message = json_decode($startBody, true)['message'] ?? $startBody;
            return ['exitCode' => 125, 'stdout' => '', 'stderr' => 'docker start failed: ' . $message];
        }

        // 阻塞等待退出;curl --max-time 兜底 wall-clock 超时
        $timeout = (int) ($this->sandboxConfig['timeout'] ?? 30);
        [$waitOk, $waitBody] = $this->dockerRequest(
            'POST',
            "/containers/{$containerId}/wait?condition=next-exit",
            timeout: $timeout,
        );

        if (! $waitOk) {
            $this->dockerRequest('POST', "/containers/{$containerId}/kill", timeout: 10);
            $this->dockerRequest('DELETE', "/containers/{$containerId}?force=1&v=1", timeout: 10);
            return ['exitCode' => 124, 'stdout' => '', 'stderr' => "sandbox timeout after {$timeout}s"];
        }

        [, $logsRaw] = $this->dockerRequest(
            'GET',
            "/containers/{$containerId}/logs?stdout=1&stderr=1&timestamps=0",
            timeout: 15,
        );
        [$stdout, $stderr] = $this->demuxDockerLogs($logsRaw);

        $this->dockerRequest('DELETE', "/containers/{$containerId}?force=1&v=1", timeout: 10);

        $exitCode = (int) (json_decode($waitBody, true)['StatusCode'] ?? -1);
        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr . sprintf("\n[sandbox finished, exit=%d]", $exitCode),
        ];
    }

    /**
     * 容器内路径 → 宿主机路径(daemon 侧挂载以其为准)
     */
    public function toHostPath(string $containerPath): string
    {
        foreach ($this->pathMap as $containerPrefix => $hostPrefix) {
            if (str_starts_with($containerPath, $containerPrefix)) {
                return $hostPrefix . substr($containerPath, strlen($containerPrefix));
            }
        }
        throw new \RuntimeException("Cannot map container path to host path: {$containerPath} (check workflow.path_map)");
    }

    /**
     * 创建一次性沙箱容器:全部隔离参数在 HostConfig 声明
     */
    private function createContainer(string $name, string $taskDir, string $image, string $fileName): ?string
    {
        $memory = $this->parseMemoryLimit((string) ($this->sandboxConfig['memory'] ?? '256m'));
        $cpus = (float) ($this->sandboxConfig['cpus'] ?? 0.5);

        $payload = [
            'Image' => $image,
            // 镜像 ENTRYPOINT 已内置 runner,此处仅传文件协议三件套
            'Cmd' => ["/task/{$fileName}", '/task/input.json', '/task/output.json'],
            'User' => '65534:65534', // nobody 非 root
            'Env' => $this->buildEnv(),
            'WorkingDir' => '/task',
            'HostConfig' => [
                // cgroup 资源三限额(OOM=137)
                'Memory' => $memory,
                'NanoCpus' => (int) ($cpus * 1_000_000_000),
                'PidsLimit' => (int) ($this->sandboxConfig['pids_limit'] ?? 64),
                // 网络/文件系统/权限隔离
                'NetworkMode' => 'none',
                'ReadonlyRootfs' => true,
                'CapDrop' => ['ALL'],
                'SecurityOpt' => array_merge(['no-new-privileges'], $this->seccompOption()),
                'Tmpfs' => ['/tmp' => 'rw,size=16m'],
                'Binds' => [$this->toHostPath($taskDir) . ':/task'],
                'AutoRemove' => false,
            ],
        ];

        [$ok, $body] = $this->dockerRequest(
            'POST',
            '/containers/create?name=' . urlencode($name),
            json_encode($payload, JSON_UNESCAPED_SLASHES),
            30,
        );
        if (! $ok) {
            return null;
        }
        return json_decode($body, true)['Id'] ?? null;
    }

    /**
     * 环境变量白名单透传(对应容器镜像默认只带 LANG;借鉴 dify allowed_env_vars)
     * @return string[]
     */
    private function buildEnv(): array
    {
        $env = [];
        foreach ($this->allowedEnvVars as $name) {
            $value = getenv($name);
            if ($value !== false && $value !== '') {
                $env[] = "{$name}={$value}";
            }
        }
        return $env !== [] ? $env : ['LANG=C.UTF-8'];
    }

    /**
     * seccomp syscall 白名单;调试可经 workflow.sandbox.enable_seccomp 关闭
     * 注意:Engine API 的 SecurityOpt 不支持 CLI 的 seccomp=<path> 语法,
     * 必须内联 profile 的完整 JSON 内容
     * @return string[]
     */
    private function seccompOption(): array
    {
        if (! ($this->sandboxConfig['enable_seccomp'] ?? true)) {
            return [];
        }
        $baseDir = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
        $profile = $baseDir . ($this->sandboxConfig['seccomp_profile'] ?? '/docker/seccomp-profile.json');
        if (! is_file($profile)) {
            return [];
        }
        // 预检:profile 必须是合法 JSON,否则 daemon create/start 直接失败
        $content = (string) file_get_contents($profile);
        if (json_decode($content) === null) {
            return [];
        }
        return ['seccomp=' . $content];
    }

    /**
     * '256m'|'1g' → 字节数
     */
    private function parseMemoryLimit(string $limit): int
    {
        $value = (int) $limit;
        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Docker Engine API 请求(经容器内 curl 走 unix socket)
     * @return array{0: bool, 1: string} [成功与否, 响应体]
     */
    private function dockerRequest(string $method, string $path, ?string $body = null, int $timeout = 30): array
    {
        $command = [
            'curl', '-s', '--max-time', (string) $timeout,
            '--unix-socket', self::DOCKER_SOCKET,
            '-X', $method,
            '-w', "\n%{http_code}",
        ];
        if ($body !== null) {
            $command = array_merge($command, ['-H', 'Content-Type: application/json', '-d', $body]);
        }
        $command[] = 'http://localhost' . $path;

        $process = new Process($command, timeout: $timeout + 5);
        $process->run();
        $output = $process->getOutput();

        // 最后一行是 http_code
        $pos = strrpos($output, "\n");
        $httpCode = (int) trim(substr($output, $pos === false ? 0 : $pos + 1));
        $responseBody = $pos === false ? '' : substr($output, 0, $pos);

        return [$httpCode >= 200 && $httpCode < 300, $responseBody];
    }

    /**
     * Docker logs API 返回多路复用帧:8 字节头(type + 4 字节大端长度)+ payload
     * type 1 = stdout,2 = stderr;tty 模式无帧,直接原文(此处按非 tty 处理并兜底)
     * @return array{0: string, 1: string} [stdout, stderr]
     */
    private function demuxDockerLogs(string $raw): array
    {
        $stdout = '';
        $stderr = '';
        $pos = 0;
        $len = strlen($raw);
        if ($len >= 8 && in_array(ord($raw[0]), [1, 2], true) && substr($raw, 1, 3) === "\0\0\0") {
            while ($pos + 8 <= $len) {
                $type = ord($raw[$pos]);
                $size = unpack('N', substr($raw, $pos + 4, 4))[1] ?? 0;
                $payload = substr($raw, $pos + 8, $size);
                if ($type === 1) {
                    $stdout .= $payload;
                } else {
                    $stderr .= $payload;
                }
                $pos += 8 + $size;
            }
            return [$stdout, $stderr];
        }
        return ['', $raw];
    }
}

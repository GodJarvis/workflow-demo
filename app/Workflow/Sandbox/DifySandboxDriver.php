<?php

declare(strict_types=1);

namespace App\Workflow\Sandbox;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 档3 沙箱:远程沙箱服务(适配本地部署的 dify-sandbox Go 服务)
 *
 * 形态即生产推荐的 sandbox-manager:独立进程、独立加固、HTTP API 调用,
 * 引擎与沙箱完全解耦(可独立扩缩容/升级/替换实现)。
 *
 * ── 协议转换(本 Driver 的核心教学点)─────────────────────────────
 * 引擎侧文件协议:  taskDir/{code.*, input.json, output.json} + runner 模板调 main()
 * dify 侧 HTTP 协议:POST /v1/sandbox/run {language, code, preload, enable_network}
 *                   → {code, message, data:{stdout, stderr, exit_code}}
 *
 * 转换三步:
 *   ① 包装代码:params 不再走文件,内联进代码(JSON_HEX_* 转义保证字符串字面量安全);
 *      追加对 main() 的调用与 __WF_OUTPUT__ 结果标记行 —— 相当于把 runner 模板
 *      "编译"进用户代码(dify 官方 api 服务调 sandbox 也是同样做法)
 *   ② stdout 中按行回收 __WF_OUTPUT__ 标记,解析出 ret
 *   ③ driver 代写 taskDir/output.json —— 上层 CodeExecutor 的读取逻辑零改动,
 *      三档沙箱对引擎完全同构("保证 output.json 产出"是 Driver 的统一契约)
 *
 * ── 语言差异说明 ────────────────────────────────────────────────
 * dify-sandbox 上游只实现 python3 / nodejs 两种 runner;php 节点请选 docker/process 档。
 * nodejs 以 CJS 跑(无顶层 await),JS 包装用 Promise.resolve(main(...)).then(...)
 *
 * ── dify-sandbox 服务侧说明(部署踩坑见 README)──────────────────
 * 服务需 root 运行(chroot/setuid 序列需要 CAP_SYS_CHROOT,官方即容器内 root);
 * X-Api-Key 鉴权;max_workers=4 信号量;worker_timeout 由服务侧兜底。
 */
class DifySandboxDriver implements SandboxDriverInterface
{
    /** 引擎语言 → dify API language 字段 */
    private const LANGUAGE_MAP = [
        'python' => 'python3',
        'javascript' => 'nodejs',
    ];

    private const OUTPUT_MARKER = '__WF_OUTPUT__';

    private string $baseUrl;

    private string $apiKey;

    private int $timeout;

    public function __construct()
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $dify = $config->get('workflow.sandbox_dify', []);
        $this->baseUrl = rtrim((string) ($dify['base_url'] ?? 'http://host.docker.internal:8194'), '/');
        $this->apiKey = (string) ($dify['api_key'] ?? 'dify-sandbox');
        $this->timeout = (int) ($dify['timeout'] ?? 15);
    }

    public function run(string $taskDir, string $language): array
    {
        $difyLanguage = self::LANGUAGE_MAP[$language] ?? null;
        if ($difyLanguage === null) {
            throw new \RuntimeException(
                "Dify sandbox driver: language '{$language}' not supported by dify-sandbox upstream "
                . '(python3/nodejs only); use docker/process driver for php nodes'
            );
        }
        $fileName = ['python' => 'code.py', 'javascript' => 'code.js'][$language];
        $content = (string) @file_get_contents($taskDir . '/' . $fileName);
        $payload = json_decode((string) @file_get_contents($taskDir . '/input.json'), true);
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];

        // ① 包装代码:runner 模板内联(params → main → __WF_OUTPUT__ 标记)
        $code = $language === 'python'
            ? $this->wrapPython($content, $params)
            : $this->wrapNodejs($content, $params);

        // ② 调 dify-sandbox HTTP API
        $start = microtime(true);
        $response = $this->request($difyLanguage, $code);
        if ($response === null) {
            return ['exitCode' => 125, 'stdout' => '', 'stderr' => "dify-sandbox unreachable at {$this->baseUrl}"];
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $stdout = (string) ($data['stdout'] ?? '');
        $stderr = (string) ($data['error'] ?? '') . "\n" . (string) ($data['stderr'] ?? '');
        $exitCode = (int) ($data['exit_code'] ?? -1);
        if (($response['code'] ?? -1) !== 0) {
            $exitCode = $exitCode !== 0 ? $exitCode : 1;
        }

        // ③ 回收 __WF_OUTPUT__ 标记 → 代写 output.json(与文件协议对齐,上层零改动)
        if ($exitCode === 0) {
            $outputs = $this->extractOutputs($stdout);
            if ($outputs === null) {
                $exitCode = 1; // 跑完了但没产出结果标记(用户代码异常路径退出)
            } else {
                file_put_contents($taskDir . '/output.json', json_encode(['ret' => $outputs], JSON_UNESCAPED_UNICODE));
            }
        }

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => trim($stderr) . sprintf("\n[sandbox finished in %dms, driver=dify, exit=%d]", (microtime(true) - $start) * 1000, $exitCode),
        ];
    }

    /**
     * Python 包装:json 文本用 JSON_HEX_APOS 转义后嵌入单引号字符串,杜绝注入歧义
     */
    private function wrapPython(string $userCode, array $params): string
    {
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return <<<PY
import json as __wf_json
__WF_PARAMS__ = __wf_json.loads('{$json}')

{$userCode}

__wf_ret = main(__WF_PARAMS__)
if not isinstance(__wf_ret, dict):
    __wf_ret = {'result': __wf_ret}
print('__WF_OUTPUT__' + __wf_json.dumps({'ret': __wf_ret}))
PY;
    }

    /**
     * NodeJS 包装:dify 以 CJS 执行(无顶层 await),用 Promise 链调 async main
     */
    private function wrapNodejs(string $userCode, array $params): string
    {
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return <<<JS
const __WF_PARAMS__ = JSON.parse('{$json}');

{$userCode}

Promise.resolve(main({ params: __WF_PARAMS__ })).then(function (ret) {
  const normalized = (ret !== null && typeof ret === 'object' && !Array.isArray(ret)) ? ret : { result: ret };
  console.log('__WF_OUTPUT__' + JSON.stringify({ ret: normalized }));
}).catch(function (e) {
  console.error(JSON.stringify({ level: 'error', msg: String((e && e.stack) || e) }));
  process.exit(1);
});
JS;
    }

    /**
     * 从 stdout 按行提取结果标记(取最后一个,防用户代码意外 print 干扰)
     * @return array<string, mixed>|null
     */
    private function extractOutputs(string $stdout): ?array
    {
        $payload = null;
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, self::OUTPUT_MARKER)) {
                $payload = substr($line, strlen(self::OUTPUT_MARKER));
            }
        }
        if ($payload === null) {
            return null;
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded['ret'] ?? null) ? $decoded['ret'] : null;
    }

    /**
     * HTTP 调用(PHP curl 扩展,Swoole 协程 hook 下非阻塞)
     * @return array<string, mixed>|null
     */
    private function request(string $language, string $code): ?array
    {
        $ch = curl_init($this->baseUrl . '/v1/sandbox/run');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Api-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'language' => $language,
                'code' => $code,
                'preload' => '',
                'enable_network' => false,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode !== 200) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }
}

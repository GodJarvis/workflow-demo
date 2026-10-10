<?php

declare(strict_types=1);

namespace App\Workflow\Engine\Executors;

use App\Workflow\Context\WorkflowContext;
use App\Workflow\Document\NodeModel;
use App\Workflow\Engine\NodeExecutorInterface;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * LLM 节点:OpenAI 兼容 Chat Completions 调用
 *
 * 对齐 runtime-js 的 LLMExecutor(index.js:724,LangChain ChatOpenAI)——
 * demo 不引 LangChain,直接走 OpenAI 兼容 HTTP 协议(PHP curl,Swoole 协程 hook 下非阻塞)。
 *
 * DSL 形态:
 *   { "type": "llm", "data": {
 *       "model": "gpt-4o-mini",            // 可省,回落 config workflow.llm.model
 *       "temperature": 0.7,
 *       "inputsSchema": { "properties": { "systemPrompt": {...}, "prompt": {...} } },
 *       "inputsValues": { "prompt": {"type":"template","content":"把这句话改写成视频分镜:{{clean.cleaned}}"} }
 *   }}
 * 输出:{ result: string }
 *
 * 配置优先级:节点 data(单节点覆盖)> config workflow.llm(全局默认)> mock。
 * mock 模式(未配置 api_key 或显式 llm.mock=true):返回模拟文本——无 key 环境教学可用。
 *
 * 异步语义:HTTP 调用在协程内挂起等待(不轮询不占 worker),长响应由 llm.timeout 兜底
 * ——这正是协程模型对"耗时节点"的天然支持,引擎零改动(详见 Engine 注释与教学手册03)。
 */
class LLMExecutor implements NodeExecutorInterface
{
    public function type(): string
    {
        return 'llm';
    }

    public function execute(WorkflowContext $context, NodeModel $node, array $inputs): array
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $llm = $config->get('workflow.llm', []);

        $apiHost = trim((string) ($node->data['apiHost'] ?? $llm['base_url'] ?? ''));
        $apiKey = (string) ($node->data['apiKey'] ?? $llm['api_key'] ?? '');
        $model = (string) ($node->data['model'] ?? $llm['model'] ?? 'gpt-4o-mini');
        $temperature = (float) ($node->data['temperature'] ?? 0.7);
        $timeout = (int) ($llm['timeout'] ?? 120);

        $prompt = (string) ($inputs['prompt'] ?? '');
        if ($prompt === '') {
            throw new \RuntimeException("LLM node '{$node->id}': input 'prompt' is required");
        }

        // mock 模式:无 key 或显式开启(教学环境零依赖)
        if (($llm['mock'] ?? false) || $apiKey === '' || $apiHost === '') {
            return [
                'outputs' => ['result' => sprintf('[mock llm:%s] %s', $model, mb_substr($prompt, 0, 200))],
                'logs' => [['level' => 'log', 'msg' => "llm mock response for model {$model}"]],
            ];
        }

        // OpenAI 兼容 chat/completions(协程 hook:等待期间协程挂起,worker 可服务其它工作流)
        $messages = [];
        $systemPrompt = trim((string) ($inputs['systemPrompt'] ?? ''));
        if ($systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $body = $this->request(
            rtrim($apiHost, '/') . '/chat/completions',
            $apiKey,
            [
                'model' => $model,
                'temperature' => $temperature,
                'messages' => $messages,
            ],
            $timeout,
        );

        $content = $body['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || $content === '') {
            throw new \RuntimeException("LLM node '{$node->id}': empty completion response");
        }

        return [
            'outputs' => ['result' => $content],
            'logs' => [[
                'level' => 'log',
                'msg' => sprintf('llm %s responded %d chars (usage: %s)', $model, mb_strlen($content), json_encode($body['usage'] ?? new \stdClass())),
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $url, string $apiKey, array $payload, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("LLM request failed: {$error}");
        }
        $decoded = json_decode((string) $raw, true);
        if ($httpCode !== 200) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $raw) : $raw;
            throw new \RuntimeException("LLM api http {$httpCode}: " . mb_substr((string) $message, 0, 300));
        }
        if (! is_array($decoded)) {
            throw new \RuntimeException('LLM api returned non-json body');
        }
        return $decoded;
    }
}

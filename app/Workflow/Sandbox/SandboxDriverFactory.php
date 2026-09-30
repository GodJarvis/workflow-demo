<?php

declare(strict_types=1);

namespace App\Workflow\Sandbox;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;

/**
 * 沙箱驱动工厂:全局默认档位(config workflow.sandbox_driver)+ 节点级覆盖
 *
 * 教学用法:同一份 DSL 在两档间切换,对比隔离强度与冷启动:
 *   全局:config/autoload/workflow.php → 'sandbox_driver' => 'process' | 'docker'
 *   节点:node.data.sandboxDriver = "process"(优先级更高)
 */
class SandboxDriverFactory
{
    public static function make(?string $driver = null): SandboxDriverInterface
    {
        $name = $driver
            ?? ApplicationContext::getContainer()
                ->get(ConfigInterface::class)
                ->get('workflow.sandbox_driver', 'docker');

        return match ($name) {
            'docker' => new DockerSandboxDriver(),
            'process' => new ProcessSandboxDriver(UidPool::global()),
            default => throw new \RuntimeException("Unknown sandbox driver '{$name}' (expect docker|process)"),
        };
    }
}

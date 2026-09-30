<?php

declare(strict_types=1);

namespace App\Workflow\Sandbox;

/**
 * 沙箱驱动统一协议:档1(进程级,dify 风格)与档2(容器级)的切换点
 *
 * 固化的文件协议(两档共用同一套 runner 模板,教学对照变量只有"执行方式"):
 *   $taskDir/code.py|code.js|code.php   用户代码(由 CodeExecutor 写入)
 *   $taskDir/input.json                 {"params": {...}}
 *   $taskDir/output.json                {"ret": {...}}(驱动负责让 runner 产出它)
 *
 * @see ProcessSandboxDriver 档1:进程级(chroot/降权/ulimit,参考 dify-sandbox)
 * @see DockerSandboxDriver  档2:一次性容器(cgroup/namespace/seccomp)
 */
interface SandboxDriverInterface
{
    /**
     * 在沙箱中执行 $taskDir 下的用户代码
     * @param string $taskDir 任务节点目录(引擎容器内路径)
     * @param string $language python | javascript | php
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function run(string $taskDir, string $language): array;
}

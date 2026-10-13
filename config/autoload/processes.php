<?php

declare(strict_types=1);

/**
 * 自定义进程注册(hyperf/process;配置式,不依赖注解扫描)
 * 随 `php bin/hyperf.php start` 由进程管理器拉起,与 HTTP worker 生命周期隔离
 */
return [
    \App\Process\WorkflowConsumerProcess::class,
];

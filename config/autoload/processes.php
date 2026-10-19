<?php

declare(strict_types=1);

/**
 * 自定义进程注册(hyperf/process;配置式,不依赖注解扫描)
 * 随 `php bin/hyperf.php start` 由进程管理器拉起,与 HTTP worker 生命周期隔离
 *
 * 唯一执行器进程(CallbackConsumerProcess 已退役 —— 回调的轻部分在 API 侧直投,
 * 重部分 resume 与 run 同池公平排队):
 *   RunConsumerProcess —— run 队列:{run}=全新执行 / {resume}=重载推进 + 对账器
 */
return [
    \App\Process\RunConsumerProcess::class,
];

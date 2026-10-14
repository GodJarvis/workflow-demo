<?php

declare(strict_types=1);

/**
 * 自定义进程注册(hyperf/process;配置式,不依赖注解扫描)
 * 随 `php bin/hyperf.php start` 由进程管理器拉起,与 HTTP worker 生命周期隔离
 *
 * 双消费进程(run / callback 拆分:回调延迟不受长任务拖累、独立扩缩容、故障隔离):
 *   RunConsumerProcess      —— run 队列:任务执行(重且长,run_pool_size)
 *   CallbackConsumerProcess —— callback 队列:回调推进 + 对账器(轻且快,callback_pool_size)
 */
return [
    \App\Process\RunConsumerProcess::class,
    \App\Process\CallbackConsumerProcess::class,
];

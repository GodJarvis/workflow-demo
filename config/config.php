<?php

declare(strict_types=1);

/**
 * 全局配置:保持最小化,demo 不引入 .env
 */
return [
    'app_env' => 'develop',
    // 注解扫描缓存:生产置 true 提速,demo 保持关闭方便改代码
    'scan_cacheable' => false,
];

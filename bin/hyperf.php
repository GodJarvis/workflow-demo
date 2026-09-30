#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Hyperf 入口:CLI 命令与 HTTP Server 共用同一容器
 * 用法:
 *   php bin/hyperf.php workflow run examples/first-flow.json --inputs='{"query":"..."}'
 *   php bin/hyperf.php start          # 启动 HTTP Server(9501)
 */

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', Hyperf\Engine\DefaultOption::hookFlags());

// 自调用匿名函数:独立作用域,保持全局命名空间干净
(function () {
    Hyperf\Di\ClassLoader::init();

    /** @var Psr\Container\ContainerInterface $container */
    $container = require BASE_PATH . '/config/container.php';

    $application = $container->get(Hyperf\Contract\ApplicationInterface::class);
    $application->run();
})();

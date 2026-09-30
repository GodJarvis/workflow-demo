<?php

/**
 * 初始化 PSR-11 DI 容器(hyperf/di 注解扫描 + 自动解析)
 */

declare(strict_types=1);

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;

$container = new Container((new DefinitionSourceFactory())());

return ApplicationContext::setContainer($container);

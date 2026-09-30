<?php

declare(strict_types=1);

/**
 * DI 绑定:领域类无接口抽象,构造依赖均可由容器自动解析(ConfigInterface 等自带绑定),
 * 此处留空即可;生产中建议为 SandboxRunner 抽接口以便替换沙箱实现(如 gRPC sandbox-manager)。
 */
return [
];

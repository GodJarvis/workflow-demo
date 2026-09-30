# PHP 沙箱镜像:CLI + 内置统一 Runner
# 构建上下文为 docker/ 目录(与 python/node 沙箱共享 runners/)
FROM php:8.3-cli-alpine

COPY runners/runner.php /runner/runner.php

USER 65534:65534

WORKDIR /task
ENTRYPOINT ["php", "/runner/runner.php"]

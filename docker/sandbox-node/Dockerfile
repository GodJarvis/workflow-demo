# Node 沙箱镜像:轻量 + 内置统一 Runner
# 构建上下文为 docker/ 目录(与 python/php 沙箱共享 runners/)
FROM node:22-slim

COPY runners/runner.mjs /runner/runner.mjs

USER 65534:65534

WORKDIR /task
ENTRYPOINT ["node", "/runner/runner.mjs"]

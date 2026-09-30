# Python 沙箱镜像:轻量 + 内置统一 Runner
# 构建上下文为 docker/ 目录(与 node/php 沙箱共享 runners/)
# 运行期安全参数(--memory/--network none/...)由引擎 docker run 时注入,镜像只负责环境
FROM python:3.12-slim

COPY runners/runner.py /runner/runner.py

# 非 root 运行(与引擎侧 --user 65534 呼应)
USER 65534:65534

WORKDIR /task
ENTRYPOINT ["python3", "/runner/runner.py"]

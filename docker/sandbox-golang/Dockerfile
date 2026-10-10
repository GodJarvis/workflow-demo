# Golang 沙箱镜像:编译型语言的"双文件壳"方案
# go run 把镜像内置 wire.go(壳)与用户 code.go 链接为同一 main package 编译执行;
# GOCACHE 挂载宿主目录跨执行复用编译缓存(编译型语言的缓存管道,见 README)
# 构建上下文为 docker/ 目录
FROM golang:1.24-alpine

COPY runners/wire.go /runner/wire.go

# 编译工具链会起子进程(compile/link),限制并行度防撞 pids-limit
ENV GOMAXPROCS=2 \
    GOCACHE=/gocache \
    CGO_ENABLED=0

USER 65534:65534

WORKDIR /task
# 双文件壳:wire.go 与 code.go 均由引擎落进 /task(go run 要求源文件同目录);
# ENTRYPOINT 写死完整命令(引擎侧对编译型语言不传 Cmd 文件参数,避免追加错位)
ENTRYPOINT ["go", "run", "/task/wire.go", "/task/code.go", \
            "/task/input.json", "/task/output.json"]

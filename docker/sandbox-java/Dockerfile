# Java 沙箱镜像:编译型语言的"壳类前置拼接"方案
# JEP 330 单文件源码模式(java 直接编译执行 .java),入口 = 文件第一个顶层类:
# ENTRYPOINT 脚本把镜像内置 Main.java(壳)与用户 code.java 拼接为单文件后执行。
# JDK 无内置 JSON,构建期从阿里云 maven 预置 gson 单 jar(无传递依赖)。
# 构建上下文为 docker/ 目录
FROM hub.wozhangwan.com/zwwl/eclipse-temurin:21.0.9_10-jdk-alpine-3.23

RUN sed -i 's#dl-cdn.alpinelinux.org#mirrors.ustc.edu.cn#g' /etc/apk/repositories \
    && apk add --no-cache ca-certificates \
    && mkdir -p /runner \
    && wget -q -O /runner/gson.jar \
       https://maven.aliyun.com/repository/central/com/google/code/gson/gson/2.10.1/gson-2.10.1.jar

COPY runners/Main.java /runner/Main.java

USER 65534:65534

WORKDIR /task
# 引擎侧已把壳模板(docker/runners/Main.java)与用户代码预拼接为 /task/code.java,
# 此处 JEP 330 单文件源码模式直接编译执行 —— 无 sh 无 cat(绕开 busybox sh 在
# seccomp 下的兼容性问题),SerialGC 减少线程数(pids-limit 内)
ENTRYPOINT ["java", "-XX:+UseSerialGC", "-cp", "/runner/gson.jar", \
            "/task/code.java", "/task/input.json", "/task/output.json"]

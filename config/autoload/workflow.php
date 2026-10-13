<?php

declare(strict_types=1);

/**
 * 工作流引擎配置
 *
 * path_map 说明(关键):引擎经 docker.sock 调用的是【宿主机 docker daemon】,
 * 因此 docker run 的 -v 挂载路径、seccomp 路径都必须是宿主机路径。
 * 这里维护「引擎容器内路径前缀 => 宿主机路径前缀」的映射,由 SandboxRunner 换算。
 */
return [
    // 任务运行目录(引擎容器内视角),任务快照/checkpoint/沙箱文件协议都落在下面
    'runs_dir' => BASE_PATH . '/.runs',

    // 任务/节点执行的超时(秒):节点级 wall-clock,docker kill 兜底
    'node_timeout' => 60,
    // 工作流整体超时(秒):HTTP 入口的 Channel 等待上限。
    // 含长耗时节点(如视频生成 mockSeconds=8 / 真实生成数分钟)的工作流需要足够大;-1 = 不限
    'workflow_timeout' => 600,

    // LLM 节点全局默认(OpenAI 兼容协议;节点 data 可逐项覆盖)
    'llm' => [
        'base_url' => '',              // 例:https://api.openai.com/v1 或内部网关;留空走 mock
        'api_key'  => '',              // 留空走 mock
        'model'    => 'gpt-4o-mini',
        'timeout'  => 120,             // 长响应兜底
        'mock'     => true,            // 教学默认:无 key 返回模拟文本;配好 key 后置 false
    ],

    // 外部回调端点鉴权(POST /workflow/callback 的 X-Callback-Token;生产换 HMAC 签名)
    'callback_token' => 'demo-token',

    // ★ 回调等待者注册表实现(按部署形态切换,四实现语义见 app/Workflow/Engine/Waiter/):
    //   'memory'       单 worker 专用(worker_num>1 时快路径必然失效,降级慢路径)—— InMemory
    //   'table'        同机多 worker(默认)—— Swoole\Table 路由 + sendMessage 定向投递(Table 只存路由凭证,Channel 不出进程)
    //   'redis_pubsub' 多实例/跨进程 —— Redis PUBLISH 广播 + 各进程常驻订阅协程(翻译器)
    //   'redis_stream' MQ 形态 —— Redis Stream + 每进程独立消费组(持久化/ACK/断线补读;生产等价 RabbitMQ/Kafka)
    //   'rabbitmq'    RabbitMQ 形态 —— fanout exchange + 每进程 exclusive 队列(广播 + 手动 ack 消费确认)
    'callback_registry' => 'table',

    // redis_pubsub / redis_stream 模式的 Redis 连接(compose 内置 redis 服务)
    'callback_redis' => [
        'host' => 'redis',
        'port' => 6379,
    ],

    // rabbitmq 模式的连接(compose 内置 rabbitmq 服务,管理界面 :15672 guest/guest)
    'callback_amqp' => [
        'host' => 'rabbitmq',
        'port' => 5672,
        'user' => 'guest',
        'password' => 'guest',
        'vhost' => '/',
    ],

    // ★ 任务队列(生产形态:async-run 入队 / 回调推进,消费者见 app/Process/WorkflowConsumerProcess)
    //   'redis_stream'(默认)—— Redis Stream 单组竞争消费(每任务只处理一次;XACK/断线补读)
    //   'rabbitmq'           —— RabbitMQ work queue(durable+persistent+ACK,跨重启不丢)
    'task_queue' => 'redis_stream',

    // 消费进程协程池大小 = 工作流并发上限(削峰;超过的任务在队列排队)
    'consumer_pool_size' => 4,

    // 引擎容器内路径前缀 => 宿主机路径前缀(供 docker run 挂载换算)
    'path_map' => [
        '/var/project/workflow-demo' => '/home/zw/www/workflow-demo',
    ],

    // 多语言沙箱镜像映射:language => 镜像名(docker 档使用,五语言全覆盖)
    'sandbox_images' => [
        'python'      => 'wf-sandbox-python:demo',
        'javascript'  => 'wf-sandbox-node:demo',
        'php'         => 'wf-sandbox-php:demo',
        'golang'      => 'wf-sandbox-golang:demo',   // 编译型:go run 双文件壳 + GOCACHE 跨执行缓存
        'java'        => 'wf-sandbox-java:demo',     // 编译型:JEP 330 单文件源码 + gson 预置
    ],

    // ★ 沙箱档位(教学三档体系):
    //   'docker'  一次性容器:cgroup/namespace/seccomp 全隔离,冷启动 ~150-300ms(默认)
    //   'process' dify-sandbox 同构的进程级:setpriv 降权 + UID 池 + ulimit,冷启动 ~30-80ms
    //   'dify'    远程沙箱服务档:HTTP 调用本地部署的 dify-sandbox(Go),生产 sandbox-manager 形态
    // 节点级可用 data.sandboxDriver 覆盖(同一工作流内混合多档做对比实验)
    'sandbox_driver' => 'docker',

    // dify 档(远程沙箱服务)配置
    'sandbox_dify' => [
        // 引擎容器经 host-gateway 访问宿主机上的 dify-sandbox 服务(见 docker-compose.yml extra_hosts)
        'base_url' => 'http://host.docker.internal:8194',
        'api_key'  => 'dify-sandbox',   // 服务 conf/config.yaml 的 app.key
        'timeout'  => 15,
    ],

    // process 档(dify 风格)专属配置
    'sandbox_process' => [
        'timeout'             => 15,   // wall-clock 超时(秒)
        'ulimit_cpu_seconds'  => 10,   // CPU 时间(秒)
        'ulimit_max_processes' => 64,  // 防进程爆炸(配合 UID 池,对应 dify 的 pids 兜底思路)
        // 按语言覆盖虚拟内存上限(KB);Node/V8 启动即预留 >1.5G 地址空间,不可照搬容器限额
        'ulimit_virtual_kb' => [
            'python' => 524288,
            'javascript' => 2097152,
            'php' => 524288,
        ],
    ],

    // 沙箱资源限额(docker 档 docker run 参数化)
    'sandbox' => [
        'memory'          => '256m',  // cgroup 内存上限,超限 OOM-kill(退出码 137);java 档自动抬到 512m
        'cpus'            => '0.5',   // CPU 配额
        'pids_limit'      => '64',    // 防 fork 炸弹(golang 并行编译由 GOMAXPROCS=2 配合)
        'timeout'         => 30,      // 解释型 wall-clock 超时(秒)
        'compiled_timeout'=> 60,      // 编译型(golang/java)超时:含编译时间,首跑 3-10s
        'enable_seccomp'  => true,    // syscall 白名单;调试时可关闭
        'seccomp_profile' => '/docker/seccomp-profile.json',  // 相对 BASE_PATH
        // 环境变量白名单(借鉴 dify-sandbox conf.allowed_env_vars):
        // 两档沙箱统一遵守 —— 只有列出的宿主环境变量才会透传进用户代码
        'allowed_env_vars' => ['PATH', 'LANG'],
    ],
];

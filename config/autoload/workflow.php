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
    // 工作流整体超时(秒)
    'workflow_timeout' => 300,

    // 引擎容器内路径前缀 => 宿主机路径前缀(供 docker run 挂载换算)
    'path_map' => [
        '/var/project/workflow-demo' => '/home/zw/www/workflow-demo',
    ],

    // 多语言沙箱镜像映射:language => 镜像名(docker 档使用)
    'sandbox_images' => [
        'python'      => 'wf-sandbox-python:demo',
        'javascript'  => 'wf-sandbox-node:demo',
        'php'         => 'wf-sandbox-php:demo',
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
        'memory'          => '256m',  // cgroup 内存上限,超限 OOM-kill(退出码 137)
        'cpus'            => '0.5',   // CPU 配额
        'pids_limit'      => '64',    // 防 fork 炸弹
        'timeout'         => 30,      // 沙箱进程 wall-clock 超时(秒),超时 docker kill(退出码 124)
        'enable_seccomp'  => true,    // syscall 白名单;调试时可关闭
        'seccomp_profile' => '/docker/seccomp-profile.json',  // 相对 BASE_PATH
        // 环境变量白名单(借鉴 dify-sandbox conf.allowed_env_vars):
        // 两档沙箱统一遵守 —— 只有列出的宿主环境变量才会透传进用户代码
        'allowed_env_vars' => ['PATH', 'LANG'],
    ],
];

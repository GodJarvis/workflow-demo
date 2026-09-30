# workflow-demo

服务端工作流引擎最小可运行 demo:**Hyperf 调度器 + 可序列化 Context + 多语言代码节点沙箱(Python/JavaScript/PHP)**。

设计蓝本为字节 FlowGram 的 `@flowgram.ai/runtime-js`(调度模型)与 Coze 工作流的代码节点协议(`main` 函数约定),将浏览器端实现翻译为服务端形态。

## 架构总览

```
CLI bin/hyperf.php ─┐
                    ├─→ WorkflowApplication ─→ Engine(调度器,协程并发)
HTTP :9501 ─────────┘        │                    │
                             │                    ├─ Start/End/Condition Executor(进程内)
                             │                    └─ CodeExecutor ─→ SandboxRunner
                             │                                        │ Docker Engine API(unix socket)
                             │                                        ▼
                             └─ TaskStore / SnapshotCenter      一次性沙箱容器
                                .runs/<taskID>/                  wf-sandbox-{python,node,php}
                                ├── task.json       任务状态机     (cgroup限额/禁网/只读/seccomp/非root)
                                ├── checkpoint.json ★可序列化Context
                                └── report.json     逐节点快照(inputs/outputs/logs/耗时/branch)
```

### 调度模型(与 FlowGram 一致)

- **递归 + 协程并发**,非拓扑排序:`Coroutine::create` 替代 `Promise.all`
- **汇聚**:后继节点要求所有前驱已执行(`canExecuteNode`),不满足静默跳过等其它分支触发
- **分支裁剪**:条件节点只返回 `branch`,引擎按 `fromPort` 裁边;被剪分支的独占后代直接标记 `executed/skipped`,防汇聚点死等
- **变量系统**:`Map<nodeID, Map<key, Variable>>`,三种流值 `constant` / `ref([nodeID,key,...path])` / `template("{{nodeID.key}}")`

### ★ 可序列化 Context

每节点成功后 `WorkflowContext::serialize()` 落盘 `checkpoint.json`(变量存储 + 已执行集合 + 节点状态)。
`workflow resume <taskID>` 反序列化恢复,从"前驱已满足但未执行"的节点继续,**不重跑已完成节点**。

### ★ 多语言代码节点(统一 Runner 协议)

用户代码约定(与 Coze/FlowGram/Dify 一致),数据以 JSON 文件过引擎↔沙箱边界(`input.json` → `output.json`),沙箱内拿不到引擎任何全局状态:

```python
# Python:          def main(params) -> dict
# JavaScript:      async function main({ params }) { return {...}; }
# PHP:             function main(array $params): array
```

#### 双档沙箱(docker / process)—— 三档体系的档1与档2

同一引擎、同一 Runner 文件协议,只有"执行方式"不同,构成控制变量的对比实验:

| | `process` 档(dify-sandbox 同构) | `docker` 档(一次性容器) |
|---|---|---|
| 隔离单元 | 进程(setpriv 降权 + UID 池 + ulimit) | 容器(namespace/cgroup/seccomp) |
| 文件系统 | 一次性私有工作目录(权限隔离) | 只读 rootfs + tmpfs + 挂载 /task |
| 网络 | ❌ 无法禁网(无 namespace) | ✅ `NetworkMode: none` 硬禁 |
| syscall | ❌ 无 seccomp(PHP 无自装锁机制) | ✅ 白名单 profile,默认 EPERM |
| 资源 | ulimit -v/-t/-u(V8 需 ≥2G 地址空间) | cgroup 三限额(OOM=137) |
| 冷启动(实测) | **~35-70ms** | ~130-250ms |
| 适用 | 内部可信/高频执行 | 开放多租户 |

**切换方式**:全局 `config/autoload/workflow.php → 'sandbox_driver'`;节点级 `data.sandboxDriver`(见 `examples/mixed-sandboxes.json`,同一工作流内两档混跑,快照 timeCost 直接对比)。

**process 档与 dify-sandbox 源码对照**(完整注释见 `ProcessSandboxDriver.php` 头部):

| dify 原版(Go) | 本 demo(PHP) |
|---|---|
| `uidpool/uid_pool.go`(chan 池) | `UidPool`(Swoole Channel ≡ Go chan,一字同构) |
| `add_seccomp.go`:chroot→no_new_privs→seccomp→setuid 序列 | `setpriv --reuid/--regid/--clear-groups --no-new-privs`(seccomp/chroot 差距保留并注明原因) |
| InitializeEnvironment + AfterExitHook(一次性临时目录) | `.proc_<uid>_<rand>/` 私有工作目录 + finally 清理 |
| conf `allowed_env_vars` | `workflow.sandbox.allowed_env_vars`(两档共用) |
| 无内存硬限制(已知弱项) | ulimit 补齐(反向借鉴) |

**docker 档**四层隔离:`--memory 256m --cpus 0.5 --pids-limit 64`(cgroup)/ `NetworkMode: none` / 只读 rootfs + tmpfs / `cap-drop ALL` + `no-new-privileges` + 非 root + seccomp 白名单(`docker/seccomp-profile.json`)。

沙箱日志协议:stderr 按 JSON Lines(`{"level":"log","msg":"..."}`),收集进节点快照。
退出码语义:`0` 成功 / `124` 超时 / `137` OOM / 其他 = 运行时错误。代码节点默认不重试。

#### 教学级踩坑实录(代码注释里都有)

1. **UID 池必须配一次性目录**:同目录被不同 UID 先后使用,Python `__pycache__` 属主是上一个 UID → `PermissionError`;dify 的一次性临时目录正是为此
2. **`mkdir(0777)` 被 umask 削成 0755**:必须显式 `chmod`,否则降权 UID 写不了 output.json
3. **Swoole worker 改名导致 pkill 失效**:旧 server 僵尸持端口,新 server "Address in use" 静默失败,测试全打到旧代码——排查性能问题第一步永远是确认"测的是新代码"
4. **Symfony Process 的 1ms 轮询在 hyperf EventLoop 里被放大到 ~200ms**:process 档改用 `Coroutine::exec`(协程挂起等待,不轮询)

## 快速开始

```bash
# 1. 起引擎容器(复用 php84-swoole-hyperf 镜像,挂 docker.sock)并装依赖
docker compose up -d wf-engine
docker exec wf-engine sh -c "cd /var/project/workflow-demo && composer install"

# 2. 构建三个语言沙箱镜像
docker compose build wf-sandbox-python wf-sandbox-node wf-sandbox-php

# 3. CLI 跑示例流(长文本 → JS 打分分支)
docker exec wf-engine sh -c "cd /var/project/workflow-demo && \
  php bin/hyperf.php workflow run examples/first-flow.json --inputs='{\"query\":\"  hello workflow engine demo  \"}'"

# 短文本 → PHP 兜底分支(JS 节点被裁剪为 skipped)
docker exec wf-engine sh -c "cd /var/project/workflow-demo && \
  php bin/hyperf.php workflow run examples/first-flow.json --inputs='{\"query\":\"  hi 小主  \"}'"

# 双档沙箱对比(教学):同一工作流内 process/docker 混跑,快照 timeCost 直接对比冷启动
docker exec wf-engine sh -c "cd /var/project/workflow-demo && \
  php bin/hyperf.php workflow run examples/mixed-sandboxes.json --inputs='{\"query\":\"compare sandbox drivers\"}'"

# 4. HTTP API(启动 Server 后,宿主 49601 端口)
docker exec -d wf-engine sh -c "cd /var/project/workflow-demo && php bin/hyperf.php start"
curl -s -X POST :49601/workflow/run -d '{"workflow":"first-flow","inputs":{"query":"hi"}}'
curl -s -X POST :49601/workflow/report -d '{"taskID":"task_xxxx"}'
curl -s -X POST :49601/workflow/validate -d '{"workflow":"first-flow"}'
curl -s -X POST :49601/workflow/resume -d '{"taskID":"task_xxxx"}'
```

## 断点恢复演示(fail-then-resume)

```bash
# 第一次运行:bad 节点 raise → 任务 failed,但 checkpoint 已保留 start 的执行状态
php bin/hyperf.php workflow run examples/fail-then-resume.json --inputs='{"value":21}'

# 修复 DSL(把 bad 节点的 raise 换成 return)后从断点恢复:
php bin/hyperf.php workflow resume <taskID>
# ✓ start 不重跑,从 bad 继续执行 → success,outputs: {"doubled": 42}
```

## 运行产物

```
.runs/task_<id>/
├── task.json                 # 任务状态机(created/running/success/failed + error)
├── checkpoint.json           # ★ 可序列化 Context(变量存储 + executedNodes + 节点状态)
├── report.json               # 逐节点快照:inputs/outputs/branch/logs/error/耗时
└── nodes/<nodeID>/
    ├── code.py|js|php        # 注入沙箱的用户代码
    ├── input.json            # {"params": {...}}
    └── output.json           # {"ret": {...}}(沙箱写出)
```

## 目录结构

```
app/Workflow/
├── Application/WorkflowApplication.php   # 应用服务(CLI/HTTP 共用)
├── Context/WorkflowContext.php           # ★ serialize()/restore() 可序列化上下文
├── Document/{Document,NodeModel}.php     # DSL → 图模型(prev/next 索引 + 环/起止校验)
├── Engine/Engine.php                     # 调度器:汇聚/裁剪/协程并发/checkpoint
├── Engine/Executors/{Start,End,Condition,Code}Executor.php
├── Sandbox/SandboxDriverInterface.php       # ★ 双档沙箱统一协议(文件协议固化)
├── Sandbox/SandboxDriverFactory.php         # 档位选择:全局配置 + 节点级覆盖
├── Sandbox/DockerSandboxDriver.php          # 档2:一次性容器(Engine API 直调)
├── Sandbox/ProcessSandboxDriver.php         # 档1:进程级(dify 同构,教学注释集大成)
├── Sandbox/UidPool.php                      # UID 池(Swoole Channel ≡ dify Go chan 池)
├── Snapshot/SnapshotCenter.php           # 逐节点快照 → report.json
├── State/WorkflowState.php               # constant/ref/template 解析 + executedNodes
├── Task/TaskStore.php                    # 任务状态机(JSON 文件存储)
└── Variable/VariableStore.php            # Map<nodeID, Map<key, Variable>>
docker/
├── runners/{runner.py,runner.mjs,runner.php}   # 三语言统一 Runner(文件协议)
├── sandbox-{python,node,php}/Dockerfile        # 轻量沙箱镜像(ENTRYPOINT 内置 runner)
└── seccomp-profile.json                         # syscall 白名单
```

## 已知简化(demo → 生产的差距)

| 项 | demo 做法 | 生产建议 |
|---|---|---|
| 沙箱调度 | 引擎容器挂 docker.sock 直调 Engine API | 独立 sandbox-manager 服务(gRPC),按租户配额 + 预热池 |
| 挂载路径 | `workflow.path_map` 手工映射容器↔宿主路径 | 沙箱专用共享存储(NFS/对象存储)或同宿主部署 |
| 文件权限 | 任务目录 chmod 0777(nobody 可写) | 专用 UID 规划 + uidmap |
| 任务存储 | JSON 文件 | DB/Redis,Task 表 + 快照表,支持查询/清理 |
| 取消/超时 | 节点级 wall-clock 超时 | 工作流级取消标志 + 沙箱批量回收 |
| Loop/子流程 | 未实现(runtime-js 有完整参照:sub() 子上下文 + cache 标志) | scopePath 可序列化作用域链 |
| 分层 | 引擎为领域组件,未套 Controller→Service→Repository | Controller 保持薄,领域层独立成包 |

## 编码约定

遵循团队护栏:协程内状态走 Context/局部变量、`BusinessException + ErrorCode`、统一响应 `{status_code, msg, data, extra}`、POST 路由带用途注释、日志走 LoggerFactory。

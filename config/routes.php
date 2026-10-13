<?php

declare(strict_types=1);

use App\Controller\WorkflowController;
use Hyperf\HttpServer\Router\Router;

// 工作流执行入口:提交 DSL 路径 + 输入,同步执行并返回 taskID/状态/输出/报告
Router::post('/workflow/run', [WorkflowController::class, 'run']);
// 查询任务执行报告(逐节点 inputs/outputs/logs/耗时 快照)
Router::post('/workflow/report', [WorkflowController::class, 'report']);
// 校验 DSL 合法性(环检测 / 起止节点 / 边引用 / 分支端口)
Router::post('/workflow/validate', [WorkflowController::class, 'validate']);
// 从最后 checkpoint 恢复执行中断的任务(演示可序列化 Context)
Router::post('/workflow/resume', [WorkflowController::class, 'resume']);
// 外部系统完成事件回调(异步节点闭环:X-Callback-Token 鉴权,落库+入队,消费进程单点推进)
Router::post('/workflow/callback', [WorkflowController::class, 'callback']);
// 生产形态异步入口:入队即返回 taskID,连接零占用(消费进程池化执行)
Router::post('/workflow/async-run', [WorkflowController::class, 'asyncRun']);
// SSE 任务进度事件流(?taskID=xxx):progress/done 事件,任务终态后关闭
Router::get('/workflow/events', [WorkflowController::class, 'events']);
// 模拟外部系统的状态查询接口(教学):对账器 pollUrl 指向这里,写文件控状态
Router::get('/workflow/mock-status', [WorkflowController::class, 'mockStatus']);

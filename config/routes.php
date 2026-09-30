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

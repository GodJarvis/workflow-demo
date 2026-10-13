<?php

declare(strict_types=1);

namespace App\Workflow\Engine;

/**
 * 任务延迟异常:队列模式(异步消费)下遇到等待外部回调的节点时抛出
 *
 * 语义:工作流【暂停而非失败】——节点进入 waiting、变量状态落 checkpoint、
 * 消费协程立即释放(等待不占执行资源);回调消息进来后由
 * completeExternalTask 从 checkpoint 重载推进。
 *
 * 仅在 AsyncContext 队列模式下由 executor 抛出;同步模式(HTTP/CLI run)
 * 的 executor 仍走"协程挂起 + 短周期终态感知"路径,两种模式互不影响。
 */
class TaskDeferredException extends \RuntimeException
{
}

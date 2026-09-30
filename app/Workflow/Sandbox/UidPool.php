<?php

declare(strict_types=1);

namespace App\Workflow\Sandbox;

use Swoole\Coroutine\Channel;

/**
 * UID 池 —— 移植自 dify-sandbox 的 internal/core/runner/uidpool/uid_pool.go
 *
 * dify 原版(Go):
 *   pool: make(chan int, max-min) + Acquire/Release(ctx)
 * 本实现与之一字同构:Go 的 buffered chan ≡ Swoole 的 Coroutine\Channel,
 * 借空即阻塞(Acquire 语义),用完归还(Release 语义)。
 *
 * 为什么需要 UID 池(教学要点):
 *   进程级沙箱里所有执行共享同一个宿主内核。给每次执行分配独立 UID 后,
 *   不同执行的用户进程之间:无法互相 kill/ptrace、文件系统互不可见(权限位),
 *   消除"租户 A 的代码干掉租户 B 的执行"这类横向干扰 —— 这是不引入容器时
 *   廉价而有效的隔离手段。容器方案(docker driver)不需要它:namespace 已隔离。
 *
 * dify 原版还做了一件配套的事:把池内 UID 追加写入 /etc/passwd,避免 Python
 * getpwuid() 触发被 seccomp 拦截的 syscall;本 demo 的 runner 不查 passwd,故省略。
 */
class UidPool
{
    private Channel $pool;

    private readonly int $min;

    private readonly int $max;

    public function __construct(int $min = 10000, int $max = 10100)
    {
        $this->min = $min;
        $this->max = $max;
        $this->pool = new Channel($max - $min);
        for ($i = $min; $i < $max; $i++) {
            $this->pool->push($i);
        }
    }

    /**
     * 借用一个空闲 UID;池空时阻塞等待(协程让出,不占 worker)
     * 等同 dify: Acquire(ctx) —— ctx 超时对应这里的 $timeout
     */
    public function acquire(float $timeout = 10.0): int
    {
        $uid = $this->pool->pop($timeout);
        if ($uid === false) {
            throw new \RuntimeException('Sandbox UID pool exhausted (dify 同款错误: ErrUIDPoolExhausted)');
        }
        return (int) $uid;
    }

    /**
     * 归还 UID(必须与 acquire 严格配对,在 finally 中调用)
     */
    public function release(int $uid): void
    {
        if ($uid >= $this->min && $uid < $this->max) {
            $this->pool->push($uid);
        }
    }

    /**
     * 池剩余可用数(观测用)
     */
    public function available(): int
    {
        return $this->pool->stats()['queue_num'] ?? 0;
    }

    // ------------------------------------------------------------------ 单例(全局池)

    private static ?self $global = null;

    public static function global(): self
    {
        return self::$global ??= new self(
            (int) (\Hyperf\Support\env('SANDBOX_UID_MIN', 10000)),
            (int) (\Hyperf\Support\env('SANDBOX_UID_MAX', 10100)),
        );
    }
}

<?php

declare(strict_types=1);

/**
 * 统一 Runner 协议(PHP)
 *
 * 用法: php /runner/runner.php <code.php> <input.json> <output.json>
 *
 * 用户代码要求: function main(array $params): array { return [...]; }
 * 非 array 返回值包装为 ['result' => ...]。
 * 日志走 stderr JSON Lines: {"level":"log","msg":"..."}
 *
 * 注意:runner 自身函数命名必须避开 main,留给用户代码
 */

/** @param resource $stream */
function wlog($stream, string $level, string $msg): void
{
    fwrite($stream, json_encode(['level' => $level, 'msg' => $msg], JSON_UNESCAPED_UNICODE) . "\n");
}

function wf_runner_main(array $argv): int
{
    global $argc;
    if ($argc !== 4) {
        wlog(STDERR, 'error', 'usage: runner.php <code.php> <input.json> <output.json>');
        return 2;
    }
    [, $codePath, $inputPath, $outputPath] = $argv;

    $params = [];
    $rawInput = @file_get_contents($inputPath);
    if ($rawInput === false) {
        wlog(STDERR, 'error', "cannot read {$inputPath}");
        return 1;
    }
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded) && array_key_exists('params', $decoded)) {
        $params = is_array($decoded['params']) ? $decoded['params'] : [];
    }

    try {
        require $codePath;
    } catch (ParseError|Throwable $e) {
        wlog(STDERR, 'error', 'failed to load user code: ' . $e->getMessage());
        return 1;
    }

    if (! function_exists('main')) {
        wlog(STDERR, 'error', 'main function is required: function main(array $params): array');
        return 1;
    }

    try {
        $ret = main($params);
        if (! is_array($ret)) {
            $ret = ['result' => $ret];
        }
        file_put_contents($outputPath, json_encode(['ret' => $ret], JSON_UNESCAPED_UNICODE));
        return 0;
    } catch (Throwable $e) {
        wlog(STDERR, 'error', $e->getMessage() . "\n" . $e->getTraceAsString());
        return 1;
    }
}

exit(wf_runner_main($argv));

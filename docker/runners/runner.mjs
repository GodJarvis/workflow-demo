#!/usr/bin/env node
/**
 * 统一 Runner 协议(JavaScript/Node)
 *
 * 用法: node /runner/runner.mjs <code.js> <input.json> <output.json>
 *
 * 用户代码要求: async function main({ params }) { return {...}; }
 * 用户代码无需 export —— 通过 data URL import 拼接 export 拿到 main,
 * 避免写临时文件;非 dict 返回值包装为 { result: ... }。
 * 日志走 stderr JSON Lines: {"level":"log","msg":"..."}
 */
import { readFileSync, writeFileSync } from 'node:fs';

const log = (level, msg) => process.stderr.write(JSON.stringify({ level, msg }) + '\n');

const [, , codePath, inputPath, outputPath] = process.argv;
if (!codePath || !inputPath || !outputPath) {
  log('error', 'usage: runner.mjs <code.js> <input.json> <output.json>');
  process.exit(2);
}

let params = {};
try {
  params = JSON.parse(readFileSync(inputPath, 'utf8')).params ?? {};
} catch (e) {
  log('error', 'invalid input.json: ' + (e.stack ?? String(e)));
  process.exit(1);
}

let mod;
try {
  const source = readFileSync(codePath, 'utf8');
  const b64 = Buffer.from(source + '\nexport { main };\n', 'utf8').toString('base64');
  mod = await import(`data:text/javascript;base64,${b64}`);
} catch (e) {
  log('error', 'failed to load user code: ' + (e.stack ?? String(e)));
  process.exit(1);
}

if (typeof mod.main !== 'function') {
  log('error', 'main function is required: async function main({ params }) { ... }');
  process.exit(1);
}

try {
  const ret = await mod.main({ params });
  const normalized = ret !== null && typeof ret === 'object' && !Array.isArray(ret) ? ret : { result: ret };
  writeFileSync(outputPath, JSON.stringify({ ret: normalized }));
} catch (e) {
  log('error', e.stack ?? String(e));
  process.exit(1);
}

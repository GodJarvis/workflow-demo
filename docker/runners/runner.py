#!/usr/bin/env python3
"""统一 Runner 协议(Python)

用法: python3 /runner/runner.py <code.py> <input.json> <output.json>

协议:
  - 读 input.json 拿 {"params": {...}}
  - 加载用户代码,要求提供 def main(params: dict) -> dict
  - main 返回值写入 output.json 的 {"ret": {...}}(非 dict 包装为 {"result": ...})
  - 日志走 stderr,每行一个 JSON:{"level": "log|info|warn|error", "msg": "..."}
"""
import importlib.util
import json
import sys
import traceback


def log(level: str, msg: str) -> None:
    print(json.dumps({"level": level, "msg": msg}, ensure_ascii=False), file=sys.stderr)


def load_user_module(code_path: str):
    spec = importlib.util.spec_from_file_location("user_code", code_path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main() -> int:
    if len(sys.argv) != 4:
        log("error", "usage: runner.py <code.py> <input.json> <output.json>")
        return 2

    code_path, input_path, output_path = sys.argv[1], sys.argv[2], sys.argv[3]

    try:
        with open(input_path, "r", encoding="utf-8") as f:
            params = json.load(f).get("params", {})
    except Exception:
        log("error", "invalid input.json: " + traceback.format_exc())
        return 1

    try:
        module = load_user_module(code_path)
    except Exception:
        log("error", "failed to load user code: " + traceback.format_exc())
        return 1

    if not hasattr(module, "main") or not callable(getattr(module, "main")):
        log("error", "main function is required: def main(params: dict) -> dict")
        return 1

    try:
        ret = module.main(params)
        if not isinstance(ret, dict):
            ret = {"result": ret}
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump({"ret": ret}, f, ensure_ascii=False, default=str)
        return 0
    except Exception:
        log("error", traceback.format_exc())
        return 1


if __name__ == "__main__":
    sys.exit(main())

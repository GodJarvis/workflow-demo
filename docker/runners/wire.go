package main

// 统一 Runner 协议(Go)—— 编译型语言的"双文件壳"方案
//
// Go 无法运行时动态 import 源文件,也不能把壳与用户代码拼成单文件(import 块
// 语法上必须在文件头)。解法:【两个文件同 package 编译】——
//   go run /runner/wire.go /task/code.go <input.json> <output.json>
// 壳(wire.go,本文件)与用户代码(code.go)各自的 import 独立声明,
// 由 go 编译器链接到同一个 main package。
//
// 用户代码约定(code.go 为完整合法 Go 文件,与其它语言的 main 协议对齐;
// Go 因与 runtime 的 main() 冲突而用导出的 Main):
//
//   package main
//
//   func Main(params map[string]any) (map[string]any, error) {
//       return map[string]any{"result": params["input"]}, nil
//   }
//
// 文件协议与 python/js/php 完全一致:读 input.json 的 params,写 output.json 的 ret。

import (
	"encoding/json"
	"os"
)

func logJSON(level, msg string) {
	_ = json.NewEncoder(os.Stderr).Encode(map[string]string{"level": level, "msg": msg})
}

func main() {
	if len(os.Args) != 3 {
		// os.Args = [程序名, input.json, output.json](go run 的文件列表不进入程序参数)
		logJSON("error", "usage: 程序 <input.json> <output.json>")
		os.Exit(2)
	}
	inputPath, outputPath := os.Args[1], os.Args[2]

	raw, err := os.ReadFile(inputPath)
	if err != nil {
		logJSON("error", "cannot read input.json: "+err.Error())
		os.Exit(1)
	}
	var payload struct {
		Params map[string]any `json:"params"`
	}
	if err := json.Unmarshal(raw, &payload); err != nil {
		logJSON("error", "invalid input.json: "+err.Error())
		os.Exit(1)
	}

	// 调用用户 code.go 中定义的 Main(同 package 链接)
	ret, err := Main(payload.Params)
	if err != nil {
		logJSON("error", err.Error())
		os.Exit(1)
	}
	if ret == nil {
		ret = map[string]any{}
	}

	out, err := json.Marshal(map[string]any{"ret": ret})
	if err != nil {
		logJSON("error", "marshal output: "+err.Error())
		os.Exit(1)
	}
	if err := os.WriteFile(outputPath, out, 0o644); err != nil {
		logJSON("error", "cannot write output.json: "+err.Error())
		os.Exit(1)
	}
}

import com.google.gson.Gson;
import com.google.gson.GsonBuilder;
import com.google.gson.reflect.TypeToken;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.HashMap;
import java.util.Map;

/**
 * 统一 Runner 协议(Java)—— 编译型语言的"壳类前置拼接"方案
 *
 * JDK 11+ 单文件源码模式(JEP 330:java Code.java 直接编译执行)只支持单文件,
 * 且入口为文件中的第一个顶层类。因此 ENTRYPOINT 脚本把【本壳模板】与用户代码
 * 拼接为单个 Main.java:壳类在前(作为入口),用户类(User)在后。
 *
 * 用户代码约定(与其它语言的 main 协议对齐;Java 因 main(String[]) 冲突用 User.main):
 *   - 只写顶层类型声明,定义 class User
 *   - 不写 import(壳已预置 java.util.* 与 gson);需要其它包时受此限制(教学取舍)
 *
 *   class User {
 *       public static Map<String, Object> main(Map<String, Object> params) {
 *           return Map.of("result", params.get("input"));
 *       }
 *   }
 *
 * 文件协议与其它语言一致:读 input.json 的 params,写 output.json 的 ret。
 * JSON 处理用 gson(JDK 无内置 JSON 解析,镜像构建时从阿里云 maven 预置单 jar)。
 */
public class Main {

    private static final Gson GSON = new GsonBuilder().disableHtmlEscaping().create();

    static void logJson(String level, String msg) {
        System.err.println(GSON.toJson(Map.of("level", level, "msg", msg)));
    }

    @SuppressWarnings("unchecked")
    public static void main(String[] args) throws Exception {
        if (args.length != 2) {
            logJson("error", "usage: java Main.java <input.json> <output.json>");
            System.exit(2);
        }
        Path inputPath = Path.of(args[0]);
        Path outputPath = Path.of(args[1]);

        Map<String, Object> payload;
        try {
            payload = GSON.fromJson(Files.readString(inputPath),
                    new TypeToken<Map<String, Object>>() {}.getType());
        } catch (Exception e) {
            logJson("error", "invalid input.json: " + e.getMessage());
            System.exit(1);
            return;
        }
        Map<String, Object> params = (Map<String, Object>) payload.getOrDefault("params", Map.of());

        Map<String, Object> ret;
        try {
            ret = User.main(params);
        } catch (Exception e) {
            logJson("error", String.valueOf(e));
            System.exit(1);
            return;
        }
        if (ret == null) {
            ret = Map.of();
        }

        try {
            Files.writeString(outputPath, GSON.toJson(Map.of("ret", ret)));
        } catch (Exception e) {
            logJson("error", "cannot write output.json: " + e.getMessage());
            System.exit(1);
        }
    }
}

// ==== 用户代码拼接区(ENTRYPOINT 脚本将 /task/code.java 内容追加到此处) ====

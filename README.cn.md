[English](README.md) | [Русский](README.ru.md) | **中文**

# mercurius-mcp-ssh

基于 [AMPHP](https://amphp.org) 和 `amphp/ssh`、通过 stdio 为单个 SSH 服务器提供服务的 MCP 服务器。它是
[Mercurius](https://github.com/opencck/mercurius) 的 SSH 模块：每台服务器是 `.mcp.json` 中的一个条目，代理核心
围绕该服务器对每次调用进行分类、征询所有者并记录日志。

## 工具

| 工具 | 作用 |
|------|------|
| `run` | 执行 shell 命令，返回退出码和输出（每个流 64 KB） |
| `read_file` | 读取文件，最多 1 MB |
| `write_file` | 通过 `cat` 写入文件，最多 1 MB |
| `tcp_probe` | 从服务器检查 TCP 端口是否可达 |

## 运行

```sh
SSH_PASSWORD=… php bin/ssh-mcp --host=srv.example --user=deploy --port=22 --host-key=SHA256:…
```

- `--key=<路径>` 使用密钥文件登录；密钥口令放在 `SSH_KEY_PASSPHRASE` 中。
- `--host-key` 是固定的主机密钥。缺少它或密钥不同时不会发送任何内容：每次调用都返回
  `UNKNOWN_HOST_KEY <指纹> new|changed`，是否固定该密钥由启动服务器的一方决定。
- 密钥只放在环境变量中，不放在参数里。

## 检查

```sh
composer install
composer check
```

## 许可证

MIT — 见 [LICENSE](LICENSE)。

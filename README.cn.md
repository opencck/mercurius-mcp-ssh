[English](README.md) | [Русский](README.ru.md) | **中文**

# mercurius-mcp-ssh

基于 [AMPHP](https://amphp.org) 和 `amphp/ssh`、通过 stdio 为单个 SSH 服务器提供服务的 MCP 服务器。它是
[Mercurius](https://github.com/opencck/mercurius) 的 SSH 模块：每台服务器是 `.mcp.json` 中的一个条目，代理核心
围绕该服务器对每次调用进行分类、征询所有者并记录日志。

## 工具

| 工具 | 作用 |
|------|------|
| `run` | 执行 shell 命令，返回退出码和输出（每个流 64 KB），同时作为 `structuredContent` 返回 |
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
- `SSH_PRIVATE_KEY` 使用以文本传入的私钥登录；私钥仅在登录期间存放于内存（`/dev/shm`）。
- `SSH_PROXY` 是到服务器的路径：`http://`、`socks5://` 或 `vless://`（
  [mercurius-connectors](https://github.com/opencck/mercurius-connectors) 的路由）。
- `--jump-host`、`--jump-user`、`--jump-port`、`--jump-host-key` 经由中间服务器连接（类似 `ssh -J`）；其登录凭据放在
  `SSH_JUMP_PASSWORD` 或 `SSH_JUMP_PRIVATE_KEY` 中，此时 `SSH_PROXY` 通向中间服务器。中间服务器的密钥未固定时返回
  `UNKNOWN_HOST_KEY <指纹> new|changed jump`。
- 以 `sudo` 开头的单段命令会获得登录密码：由 `sudo -S` 从 stdin 读取，命令本身的 stdin 为 `/dev/null`。输出中的
  密码及其 base64 和 hex 形式会被替换为 `***`。
- 没有 `--host` 时服务器照常启动并列出工具，每次调用会说明缺少什么：安装时就是这样试运行的。
- 密钥只放在环境变量中，不放在参数里。

## 安装到 Mercurius

`mercurius.mcp.json` 中是安装计划：`composer install --no-dev`，然后是条目 `php
${INSTALL_DIR}/bin/ssh-mcp`。每台服务器是 `.mcp.json` 中的一个条目，带有自己的参数和密钥。

## 检查

```sh
composer install
composer check
```

## 许可证

MIT — 见 [LICENSE](LICENSE)。

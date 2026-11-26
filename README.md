**English** | [Русский](README.ru.md) | [中文](README.cn.md)

# mercurius-mcp-ssh

An MCP server over stdio for one SSH server, built on [AMPHP](https://amphp.org) and `amphp/ssh`. It is the SSH
module of [Mercurius](https://github.com/opencck/mercurius): each server is one entry of `.mcp.json`, and the agent's
core classifies every call, asks the owner and keeps the journal around this server.

## Tools

| Tool | What it does |
|------|--------------|
| `run` | Runs a shell command, returns the exit code and output (64 KB of each stream), also as `structuredContent` |
| `read_file` | Reads a file, up to 1 MB |
| `write_file` | Writes a file through `cat`, up to 1 MB |
| `tcp_probe` | Checks from the server whether a TCP port answers |

## Running

```sh
SSH_PASSWORD=… php bin/ssh-mcp --host=srv.example --user=deploy --port=22 --host-key=SHA256:…
```

- `--key=<path>` logs in by a key file; its passphrase is in `SSH_KEY_PASSPHRASE`.
- `--host-key` is the pinned key of the host. Without it, or with another key, nothing is sent: every call answers
  `UNKNOWN_HOST_KEY <fingerprint> new|changed`, and whoever started the server decides whether to pin it.
- `SSH_PRIVATE_KEY` logs in by a key given as text; it lives in memory (`/dev/shm`) only for the login.
- `SSH_PROXY` is the way to the server: `http://`, `socks5://` or `vless://` (routes of
  [mercurius-connectors](https://github.com/opencck/mercurius-connectors)).
- `--jump-host`, `--jump-user`, `--jump-port`, `--jump-host-key` go through a server in between (like `ssh -J`); its
  login is in `SSH_JUMP_PASSWORD` or `SSH_JUMP_PRIVATE_KEY`, `SSH_PROXY` then leads to it. An unpinned key of it
  answers `UNKNOWN_HOST_KEY <fingerprint> new|changed jump`.
- A command of one part that starts with `sudo` gets the login password: `sudo -S` reads it on stdin, the command
  itself gets `/dev/null`. The password, its base64 and hex are replaced by `***` in the output.
- Without `--host` the server starts and lists its tools, and every call says what is missing: an install tries it so.
- Secrets go in the environment only, never in the arguments.

## Install into Mercurius

`mercurius.mcp.json` holds the install plan: `composer install --no-dev`, then the entry `php
${INSTALL_DIR}/bin/ssh-mcp`. Each server is an entry of `.mcp.json` with its own arguments and secrets.

## Checks

```sh
composer install
composer check
```

## License

MIT — see [LICENSE](LICENSE).

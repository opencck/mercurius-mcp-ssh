**English** | [Русский](README.ru.md) | [中文](README.cn.md)

# mercurius-mcp-ssh

An MCP server over stdio for one SSH server, built on [AMPHP](https://amphp.org) and `amphp/ssh`. It is the SSH
module of [Mercurius](https://github.com/opencck/mercurius): each server is one entry of `.mcp.json`, and the agent's
core classifies every call, asks the owner and keeps the journal around this server.

## Tools

| Tool | What it does |
|------|--------------|
| `run` | Runs a shell command, returns the exit code and output (64 KB of each stream) |
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
- Secrets go in the environment only, never in the arguments.

## Checks

```sh
composer install
composer check
```

## License

MIT — see [LICENSE](LICENSE).

[English](README.md) | **Русский** | [中文](README.cn.md)

# mercurius-mcp-ssh

MCP-сервер по stdio для одного SSH-сервера на [AMPHP](https://amphp.org) и `amphp/ssh`. Это SSH-модуль
[Меркурия](https://github.com/opencck/mercurius): каждый сервер — запись `.mcp.json`, а ядро агента классифицирует
каждый вызов, спрашивает владельца и ведёт журнал вокруг этого сервера.

## Инструменты

| Инструмент | Что делает |
|------------|------------|
| `run` | Выполняет команду оболочки, возвращает код выхода и вывод (по 64 КБ каждого потока), также как `structuredContent` |
| `read_file` | Читает файл, до 1 МБ |
| `write_file` | Пишет файл через `cat`, до 1 МБ |
| `tcp_probe` | Проверяет с сервера, отвечает ли TCP-порт |

## Запуск

```sh
SSH_PASSWORD=… php bin/ssh-mcp --host=srv.example --user=deploy --port=22 --host-key=SHA256:…
```

- `--key=<путь>` — вход по файлу ключа; пароль ключа — в `SSH_KEY_PASSPHRASE`.
- `--host-key` — закреплённый ключ хоста. Без него или при другом ключе ничего не отправляется: каждый вызов
  отвечает `UNKNOWN_HOST_KEY <отпечаток> new|changed`, а закрепить ли ключ, решает тот, кто запустил сервер.
- `SSH_PRIVATE_KEY` — вход по ключу, переданному текстом; ключ лежит в памяти (`/dev/shm`) только на время входа.
- `SSH_PROXY` — путь к серверу: `http://`, `socks5://` или `vless://` (маршруты
  [mercurius-connectors](https://github.com/opencck/mercurius-connectors)).
- `--jump-host`, `--jump-user`, `--jump-port`, `--jump-host-key` — через промежуточный сервер (как `ssh -J`); его вход —
  в `SSH_JUMP_PASSWORD` или `SSH_JUMP_PRIVATE_KEY`, `SSH_PROXY` тогда ведёт к нему. Незакреплённый ключ промежуточного
  сервера — ответ `UNKNOWN_HOST_KEY <отпечаток> new|changed jump`.
- Команда из одной части, начинающаяся с `sudo`, получает пароль входа: его читает `sudo -S` из stdin, у самой
  команды stdin — `/dev/null`. Пароль, его base64 и hex заменяются в выводе на `***`.
- Без `--host` сервер запускается и отдаёт список инструментов, а каждый вызов говорит, чего не хватает: так его
  пробует установка.
- Секреты — только в окружении, не в аргументах.

## Установка в Меркурий

В `mercurius.mcp.json` — план установки: `composer install --no-dev`, затем запись `php
${INSTALL_DIR}/bin/ssh-mcp`. Каждый сервер — запись `.mcp.json` со своими аргументами и секретами.

## Проверки

```sh
composer install
composer check
```

## Лицензия

MIT — см. [LICENSE](LICENSE).

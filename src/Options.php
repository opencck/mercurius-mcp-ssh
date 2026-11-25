<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

/**
 * How to reach the server: the host, port, login, a key file, the pinned host key — from the arguments; the password
 * and the key's passphrase — from the environment, so other processes do not see them in the list of processes.
 */
final class Options {
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly ?string $keyFile,
        public readonly ?string $hostKey,
        #[\SensitiveParameter] public readonly ?string $password,
        #[\SensitiveParameter] public readonly ?string $passphrase,
    ) {}

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @throws \InvalidArgumentException
     */
    public static function from(array $argv, array $env): self {
        $values = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('~^--([a-z-]+)=(.*)$~s', $arg, $m) === 1) {
                $values[$m[1]] = $m[2];
            }
        }
        $host = $values['host'] ?? '';
        $user = $values['user'] ?? '';
        if ($host === '' || $user === '') {
            throw new \InvalidArgumentException('--host and --user are needed');
        }
        $port = (int) ($values['port'] ?? '22');
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('--port is 1-65535');
        }
        $hostKey = $values['host-key'] ?? '';
        $password = $env['SSH_PASSWORD'] ?? '';
        $passphrase = $env['SSH_KEY_PASSPHRASE'] ?? '';
        return new self($host, $port, $user, ($values['key'] ?? '') === '' ? null : $values['key'], $hostKey === '' ? null : $hostKey, $password === '' ? null : $password, $passphrase === '' ? null : $passphrase);
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

/**
 * How to reach the server: the host, port, login, a key file, the pinned host key and a server in between — from the
 * arguments; the password, a private key, a key's passphrase and a proxy with its credentials — from the environment,
 * so other processes do not see them in the list of processes. Without a host the server still starts and lists its
 * tools (an install tries it so), and every call says what is missing.
 */
final class Options {
    public function __construct(
        public readonly ?string $host,
        public readonly int $port,
        public readonly ?string $user,
        public readonly ?string $keyFile,
        public readonly ?string $hostKey,
        #[\SensitiveParameter] public readonly ?string $password,
        #[\SensitiveParameter] public readonly ?string $passphrase,
        #[\SensitiveParameter] public readonly ?string $privateKey = null,
        #[\SensitiveParameter] public readonly ?string $proxy = null,
        public readonly ?Options $jump = null,
    ) {}

    public function configured(): bool {
        return $this->host !== null && $this->user !== null;
    }

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
        $value = static fn(string $key): ?string => ($values[$key] ?? '') === '' ? null : $values[$key];
        $secret = static fn(string $key): ?string => ($env[$key] ?? '') === '' ? null : $env[$key];
        $host = $value('host');
        $user = $value('user');
        if (($host === null) !== ($user === null)) {
            throw new \InvalidArgumentException('--host and --user go together');
        }
        $jump = null;
        if ($value('jump-host') !== null) {
            if ($value('jump-user') === null) {
                throw new \InvalidArgumentException('--jump-host needs --jump-user');
            }
            $jump = new self($value('jump-host'), self::port($value('jump-port')), $value('jump-user'), null, $value('jump-host-key'), $secret('SSH_JUMP_PASSWORD'), null, $secret('SSH_JUMP_PRIVATE_KEY'), $secret('SSH_PROXY'));
        }
        return new self(
            $host,
            self::port($value('port')),
            $user,
            $value('key'),
            $value('host-key'),
            $secret('SSH_PASSWORD'),
            $secret('SSH_KEY_PASSPHRASE'),
            $secret('SSH_PRIVATE_KEY'),
            // with a server in between the proxy leads to that one: the next goes through its tunnel
            $jump === null ? $secret('SSH_PROXY') : null,
            $jump,
        );
    }

    private static function port(?string $value): int {
        $port = (int) ($value ?? '22');
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('a port is 1-65535');
        }
        return $port;
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Ssh\Authentication\PublicKey;
use Amp\Ssh\Authentication\UsernamePassword;
use Amp\Ssh\HostKey\HostKeyVerifier;
use Amp\Ssh\Process;
use Amp\Socket\SocketConnector;
use Amp\Ssh\SshResource;
use Mercurius\Connectors\RouteFactory;
use Amp\TimeoutCancellation;
use Throwable;

use function Amp\async;
use function Amp\Ssh\connect;

/**
 * One SSH connection to one server, opened on the first command and kept; a connection that died is opened again once.
 * The host key must be the pinned one: an unknown or changed key stops the connection before any login. The way there
 * is directly, through a proxy or VLESS (SSH_PROXY), or through a server in between (its tunnel; the proxy then
 * leads to that one).
 */
final class SshRemote implements Remote {
    private const CONNECT_SECONDS = 20;
    /** output kept of each stream; the rest is counted and cut */
    public const OUTPUT_MAX = 65536;

    private ?SshResource $ssh = null;
    private ?SshResource $jumpSsh = null;

    public function __construct(private readonly Options $options) {}

    public function run(string $command, #[\SensitiveParameter] ?string $stdin, int $timeout): Result {
        for ($try = 0; ; $try++) {
            $ssh = $this->connection();
            $process = new Process($ssh, sprintf('timeout --kill-after=5 %d sh -c %s', $timeout, self::quote($command)));
            try {
                $process->start();
            } catch (Throwable $e) {
                // a kept connection that died quietly: nothing ran yet, so one fresh connection is tried
                $this->drop();
                if ($try === 0) {
                    continue;
                }
                throw new RemoteFailure('The command did not start: ' . $e->getMessage());
            }
            return $this->finish($process, $stdin, $timeout);
        }
    }

    private function finish(Process $process, #[\SensitiveParameter] ?string $stdin, int $timeout): Result {
        try {
            if ($stdin !== null) {
                $process->getStdin()->write($stdin);
            }
            $process->getStdin()->end();
            $deadline = new TimeoutCancellation((float) $timeout + 15.0);
            $out = async(static fn(): Drained => self::drain($process->getStdout(), $deadline));
            $err = async(static fn(): Drained => self::drain($process->getStderr(), $deadline));
            $stdout = self::drained($out);
            $stderr = self::drained($err);
            $exit = $process->join($deadline);
            return new Result($exit === false ? null : $exit, $stdout->kept, $stderr->kept, $stdout->cut || $stderr->cut);
        } catch (CancelledException) {
            $this->drop();
            throw new RemoteFailure(sprintf('The command did not end in %d s', $timeout));
        } catch (Throwable $e) {
            $this->drop();
            throw new RemoteFailure('The command failed: ' . $e->getMessage());
        }
    }

    private function connection(): SshResource {
        if ($this->ssh !== null && !$this->ssh->isClosed()) {
            return $this->ssh;
        }
        $this->drop();
        $options = $this->options;
        $jump = $options->jump;
        if ($jump !== null) {
            $this->jumpSsh = $this->connect($jump, self::route($jump->proxy), true);
        }
        try {
            return $this->ssh = $this->connect($options, $this->jumpSsh === null ? self::route($options->proxy) : new TunnelConnector($this->jumpSsh), false);
        } catch (\Throwable $e) {
            $this->drop();
            throw $e;
        }
    }

    /** A route to the first server: a proxy or VLESS by its URI, null — directly. */
    private static function route(#[\SensitiveParameter] ?string $uri): ?SocketConnector {
        if ($uri === null) {
            return null;
        }
        try {
            return RouteFactory::fromUri($uri);
        } catch (\Throwable) {
            throw new RemoteFailure('SSH_PROXY is not a route this server reads (http://, socks5:// or vless://)');
        }
    }

    /** One SSH login: the pinned host key checked first, then a password or a key. */
    private function connect(Options $options, ?SocketConnector $connector, bool $isJump): SshResource {
        $verifier = new class ($options->hostKey, $isJump) implements HostKeyVerifier {
            public function __construct(private readonly ?string $pinned, private readonly bool $jump) {}

            public function verify(string $host, int $port, string $format, string $key): void {
                $fingerprint = SshRemote::fingerprint($key);
                if ($this->pinned === null || !hash_equals($this->pinned, $fingerprint)) {
                    throw new HostKeyRefused($fingerprint, $this->pinned !== null, $this->jump);
                }
            }
        };
        $keyFile = null;
        try {
            if ($options->privateKey !== null) {
                // the library reads a key only from a file: one in memory where there is such a place, removed right
                // after the login, empty while its rights are not yet 600
                $keyFile = (is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir()) . '/ssh-mcp-' . bin2hex(random_bytes(12));
                file_put_contents($keyFile, '');
                chmod($keyFile, 0600);
                file_put_contents($keyFile, rtrim($options->privateKey) . "
");
            }
            $file = $keyFile ?? $options->keyFile;
            $auth = $file !== null
                ? new PublicKey((string) $options->user, $file, $options->passphrase ?? '')
                : new UsernamePassword((string) $options->user, $options->password ?? '');
            return connect(
                sprintf('%s:%d', (string) $options->host, $options->port),
                $auth,
                cancellation: new TimeoutCancellation(self::CONNECT_SECONDS),
                hostKeyVerifier: $verifier,
                connector: $connector,
            );
        } catch (HostKeyRefused | RemoteFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            $previous = $e->getPrevious();
            if ($previous instanceof HostKeyRefused) {
                throw $previous;
            }
            throw new RemoteFailure(sprintf('No connection to %s%s:%d: %s', $isJump ? 'the server in between ' : '', (string) $options->host, $options->port, self::reason($e)));
        } finally {
            if ($keyFile !== null) {
                @unlink($keyFile);
            }
        }
    }

    private function drop(): void {
        foreach ([$this->ssh, $this->jumpSsh] as $resource) {
            try {
                $resource?->close();
            } catch (Throwable) {
                // already gone
            }
        }
        $this->ssh = null;
        $this->jumpSsh = null;
    }

    /** The fingerprint of a host key as OpenSSH writes it: SHA256 and base64 without padding. */
    public static function fingerprint(string $key): string {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', $key, true)), '=');
    }

    public static function quote(string $text): string {
        return "'" . str_replace("'", "'\\''", $text) . "'";
    }

    private static function drain(ReadableStream $stream, Cancellation $cancellation): Drained {
        $kept = '';
        $total = 0;
        while (($chunk = $stream->read($cancellation)) !== null) {
            $total += strlen($chunk);
            if (strlen($kept) < self::OUTPUT_MAX) {
                $kept .= substr($chunk, 0, self::OUTPUT_MAX - strlen($kept));
            }
        }
        return new Drained($kept, $total > self::OUTPUT_MAX);
    }

    /** @param \Amp\Future<mixed> $future */
    private static function drained(\Amp\Future $future): Drained {
        /** @psalm-suppress MixedAssignment the future of a drain holds a Drained; checked */
        $value = $future->await();
        return $value instanceof Drained ? $value : throw new RemoteFailure('The output was not read');
    }

    private static function reason(Throwable $e): string {
        return match (true) {
            $e instanceof \Amp\Ssh\Authentication\AuthenticationFailureException => 'the login was refused',
            $e instanceof \Amp\Socket\ConnectException => 'the host does not answer',
            $e instanceof CancelledException => 'no answer in ' . self::CONNECT_SECONDS . ' s',
            default => (new \ReflectionClass($e))->getShortName(),
        };
    }
}

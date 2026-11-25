<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableStream;

/**
 * The MCP side: JSON-RPC lines over stdin and stdout, the tools of one server. The server decides nothing about what
 * a command may do: the client that started it (Mercurius) classifies each call, asks its owner and keeps the journal.
 */
final class Server {
    public const PROTOCOL = '2025-06-18';
    private const TIMEOUT_DEFAULT = 60;
    private const TIMEOUT_MAX = 900;
    private const FILE_MAX = 1_048_576;
    private const LINE_MAX = 4_194_304;

    public function __construct(private readonly Remote $remote) {}

    /** @return list<array<string, mixed>> */
    public static function tools(): array {
        $path = ['type' => 'string', 'maxLength' => 4096];
        return [
            ['name' => 'run', 'description' => 'Runs a shell command on the server and returns its exit code and output.', 'inputSchema' => ['type' => 'object', 'properties' => ['command' => ['type' => 'string', 'maxLength' => 20000], 'stdin' => ['type' => 'string', 'maxLength' => self::FILE_MAX], 'timeout' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::TIMEOUT_MAX]], 'required' => ['command'], 'additionalProperties' => false]],
            ['name' => 'read_file', 'description' => 'Reads a file of the server, up to 1 MB.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => $path], 'required' => ['path'], 'additionalProperties' => false], 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'write_file', 'description' => 'Writes a file on the server (the content goes through cat), up to 1 MB.', 'inputSchema' => ['type' => 'object', 'properties' => ['path' => $path, 'content' => ['type' => 'string', 'maxLength' => self::FILE_MAX]], 'required' => ['path', 'content'], 'additionalProperties' => false]],
            ['name' => 'tcp_probe', 'description' => 'Checks from the server whether a TCP port answers.', 'inputSchema' => ['type' => 'object', 'properties' => ['host' => ['type' => 'string', 'maxLength' => 253], 'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535]], 'required' => ['host', 'port'], 'additionalProperties' => false], 'annotations' => ['readOnlyHint' => true]],
        ];
    }

    /** Reads requests until stdin ends; each answer is one line. */
    public function serve(ReadableStream $in, WritableStream $out): void {
        $buffer = '';
        while (($chunk = $in->read()) !== null) {
            $buffer .= $chunk;
            while (($end = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $end));
                $buffer = substr($buffer, $end + 1);
                $reply = $line === '' ? null : $this->handle($line);
                if ($reply !== null) {
                    $out->write(json_encode($reply, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                }
            }
            if (strlen($buffer) > self::LINE_MAX) {
                $buffer = '';
            }
        }
    }

    /** @return array<string, mixed>|null the answer; null — a notification */
    public function handle(string $line): ?array {
        try {
            /** @var mixed $message */
            $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']];
        }
        if (!is_array($message) || !is_string($message['method'] ?? null)) {
            return null;
        }
        /** @var mixed $id */
        $id = $message['id'] ?? null;
        if ($id === null) {
            return null;
        }
        /** @psalm-suppress MixedAssignment the request is any JSON; each value is checked where it is used */
        $params = $message['params'] ?? null;
        $params = is_array($params) ? $params : [];
        $result = match ($message['method']) {
            'initialize' => ['protocolVersion' => self::PROTOCOL, 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => ['name' => 'mercurius-mcp-ssh', 'version' => '1.0.0']],
            'tools/list' => ['tools' => self::tools()],
            'tools/call' => $this->call($params),
            'ping' => new \stdClass(),
            default => null,
        };
        return $result === null
            ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found']]
            : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array{content: list<array{type: string, text: string}>, isError: bool}
     */
    private function call(array $params): array {
        /** @psalm-suppress MixedAssignment the arguments are any JSON; each is checked where it is used */
        $args = $params['arguments'] ?? null;
        $args = is_array($args) ? $args : [];
        $text = static function (string $key) use ($args): ?string {
            /** @psalm-suppress MixedAssignment */
            $value = $args[$key] ?? null;
            return is_string($value) ? $value : null;
        };
        $number = static function (string $key) use ($args): ?int {
            /** @psalm-suppress MixedAssignment */
            $value = $args[$key] ?? null;
            return is_int($value) ? $value : null;
        };
        try {
            return match ($params['name'] ?? null) {
                'run' => self::shown($this->remote->run($text('command') ?? '', $text('stdin'), self::timeout($number('timeout')))),
                'read_file' => self::file($this->remote->run('head -c ' . self::FILE_MAX . ' -- ' . SshRemote::quote($text('path') ?? ''), null, self::TIMEOUT_DEFAULT)),
                'write_file' => self::shown($this->remote->run('cat > ' . SshRemote::quote($text('path') ?? ''), $text('content') ?? '', self::TIMEOUT_DEFAULT)),
                'tcp_probe' => self::shown($this->remote->run(self::probe($text('host') ?? '', $number('port') ?? 0), null, 15)),
                default => self::answer('Unknown tool', true),
            };
        } catch (HostKeyRefused | RemoteFailure $e) {
            return self::answer($e->getMessage(), true);
        }
    }

    /** A port check from the server, without nc: bash's /dev/tcp, cut at 5 seconds. */
    private static function probe(string $host, int $port): string {
        if (preg_match('~^[A-Za-z0-9.:-]{1,253}$~', $host) !== 1 || $port < 1 || $port > 65535) {
            return 'echo "bad host or port" >&2; exit 2';
        }
        return sprintf("timeout 5 bash -c %s && echo open || { echo closed; exit 1; }", SshRemote::quote(sprintf('exec 3<>/dev/tcp/%s/%d', $host, $port)));
    }

    private static function timeout(?int $value): int {
        return $value === null ? self::TIMEOUT_DEFAULT : max(1, min(self::TIMEOUT_MAX, $value));
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private static function shown(Result $result): array {
        $parts = ['exit ' . ($result->exit ?? 'none')];
        if ($result->stdout !== '') {
            $parts[] = "stdout:\n" . rtrim($result->stdout, "\n");
        }
        if ($result->stderr !== '') {
            $parts[] = "stderr:\n" . rtrim($result->stderr, "\n");
        }
        if ($result->cut) {
            $parts[] = '(output cut)';
        }
        return self::answer(implode("\n\n", $parts), $result->exit !== 0);
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private static function file(Result $result): array {
        return $result->exit === 0 ? self::answer($result->stdout, false) : self::answer(trim($result->stderr) === '' ? 'The file was not read' : trim($result->stderr), true);
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: bool} */
    private static function answer(string $text, bool $error): array {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $error];
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\McpSsh\Test;

use Mercurius\McpSsh\HostKeyRefused;
use Mercurius\McpSsh\Remote;
use Mercurius\McpSsh\Result;
use Mercurius\McpSsh\Server;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase {
    /** @return array{Server, FakeRemote} */
    private static function server(?Result $result = null, ?\Throwable $failure = null): array {
        $remote = new FakeRemote($result ?? new Result(0, "ok\n", ''), $failure);
        return [new Server($remote), $remote];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<array-key, mixed>
     */
    private static function call(Server $server, string $tool, array $args): array {
        $reply = $server->handle(json_encode(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]], JSON_THROW_ON_ERROR));
        self::assertIsArray($reply);
        $result = $reply['result'] ?? null;
        self::assertIsArray($result);
        return $result;
    }

    /** @param array<array-key, mixed> $result */
    private static function text(array $result): string {
        $content = $result['content'] ?? null;
        self::assertIsArray($content);
        $first = $content[0] ?? null;
        self::assertIsArray($first);
        $text = $first['text'] ?? null;
        self::assertIsString($text);
        return $text;
    }

    public function testItIntroducesItselfAndListsItsTools(): void {
        [$server] = self::server();

        $init = $server->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');
        $list = $server->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');

        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{}},"serverInfo":{"name":"mercurius-mcp-ssh","version":"1.0.0"}}}', json_encode($init, JSON_THROW_ON_ERROR));
        self::assertSame(['run', 'read_file', 'write_file', 'tcp_probe'], array_column(Server::tools(), 'name'));
        self::assertSame(2, $list['id'] ?? null);
        self::assertNull($server->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}'), 'a notification has no answer');
    }

    public function testACommandRunsAndItsExitAndOutputComeBack(): void {
        [$server, $remote] = self::server(new Result(3, "out\n", "err\n"));

        $result = self::call($server, 'run', ['command' => 'ls /nope', 'timeout' => 9999]);

        self::assertSame([['ls /nope', null, 900]], $remote->runs, 'the timeout is held at its limit');
        self::assertTrue($result['isError']);
        self::assertSame("exit 3\n\nstdout:\nout\n\nstderr:\nerr", self::text($result));
    }

    public function testAFileIsWrittenThroughCatAndReadWithALimit(): void {
        [$server, $remote] = self::server();

        self::call($server, 'write_file', ['path' => "/etc/it's", 'content' => 'data']);
        self::call($server, 'read_file', ['path' => '/etc/hosts']);

        self::assertSame(["cat > '/etc/it'\\''s'", 'data', 60], $remote->runs[0]);
        self::assertSame("head -c 1048576 -- '/etc/hosts'", $remote->runs[1][0]);
    }

    public function testAProbeTakesOnlyAHostAndAPort(): void {
        [$server, $remote] = self::server();

        self::call($server, 'tcp_probe', ['host' => 'db.local', 'port' => 5432]);
        self::call($server, 'tcp_probe', ['host' => 'x; rm -rf /', 'port' => 22]);

        self::assertStringContainsString("exec 3<>/dev/tcp/db.local/5432", $remote->runs[0][0]);
        self::assertStringNotContainsString('rm -rf', $remote->runs[1][0]);
    }

    public function testAnUnknownHostKeyIsAnErrorThatNamesItsFingerprint(): void {
        [$server] = self::server(null, new HostKeyRefused('SHA256:abc', false));

        $result = self::call($server, 'run', ['command' => 'id']);

        self::assertTrue($result['isError']);
        self::assertSame('UNKNOWN_HOST_KEY SHA256:abc new', self::text($result));
    }
}

final class FakeRemote implements Remote {
    /** @var list<array{string, ?string, int}> */
    public array $runs = [];

    public function __construct(private readonly Result $result, private readonly ?\Throwable $failure) {}

    public function run(string $command, ?string $stdin, int $timeout): Result {
        $this->runs[] = [$command, $stdin, $timeout];
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->result;
    }
}

<?php

declare(strict_types=1);

namespace Mercurius\McpSsh\Test;

use Mercurius\McpSsh\HostKeyRefused;
use Mercurius\McpSsh\Options;
use Mercurius\McpSsh\Remote;
use Mercurius\McpSsh\Result;
use Mercurius\McpSsh\Server;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase {
    /** @return array{Server, FakeRemote} */
    private static function server(?Result $result = null, ?\Throwable $failure = null, ?Options $options = null): array {
        $remote = new FakeRemote($result ?? new Result(0, "ok\n", ''), $failure);
        return [new Server($remote, $options ?? Options::from(['ssh-mcp', '--host=h', '--user=u'], [])), $remote];
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

    public function testTheStreamsComeApartAsStructuredContent(): void {
        [$server] = self::server(new Result(1, "out
", "err
", true));

        $result = self::call($server, 'run', ['command' => 'false']);

        self::assertSame(['exit' => 1, 'stdout' => "out
", 'stderr' => "err
", 'cut' => true], $result['structuredContent'] ?? null);
    }

    public function testASudoCommandGetsThePasswordOnStdinAndNeverSeesIt(): void {
        $password = 'pa55word';
        [$server, $remote] = self::server(new Result(0, "pa55word cGE1NXdvcmQ=\n", ''), null, Options::from(['ssh-mcp', '--host=h', '--user=u'], ['SSH_PASSWORD' => $password]));

        $result = self::call($server, 'run', ['command' => 'sudo systemctl restart nginx']);
        self::call($server, 'run', ['command' => 'sudo ls; cat /etc/shadow']);

        self::assertSame(["sudo -S -p '' sh -c 'systemctl restart nginx < /dev/null'", "pa55word\n", 60], $remote->runs[0]);
        self::assertSame(['sudo ls; cat /etc/shadow', null, 60], $remote->runs[1], 'a command of several parts gets no password');
        self::assertStringNotContainsString($password, self::text($result));
        self::assertStringNotContainsString('cGE1NXdvcmQ', self::text($result), 'nor its base64');
    }

    public function testASudoCommandTakesNoStdinOfItsOwn(): void {
        [$server, $remote] = self::server(null, null, Options::from(['ssh-mcp', '--host=h', '--user=u'], ['SSH_PASSWORD' => 'pa55word']));

        $result = self::call($server, 'run', ['command' => 'sudo tee /etc/x', 'stdin' => 'data']);

        self::assertTrue($result['isError']);
        self::assertSame([], $remote->runs);
    }

    public function testAnEntryWithoutAServerSaysWhatIsMissing(): void {
        [$server, $remote] = self::server(null, null, Options::from(['ssh-mcp'], []));

        $result = self::call($server, 'run', ['command' => 'id']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('--host', self::text($result));
        self::assertSame([], $remote->runs);
    }

    public function testAFileIsWrittenThroughCatAndReadWithALimit(): void {
        [$server, $remote] = self::server();

        self::call($server, 'write_file', ['path' => "/etc/it's", 'content' => 'data']);
        self::call($server, 'read_file', ['path' => '/etc/hosts']);

        self::assertSame(["cat > '/etc/it'\\''s'", 'data', 60], $remote->runs[0]);
        self::assertSame("head -c 1048576 -- '/etc/hosts'", $remote->runs[1][0]);
        self::assertSame([65536, 1048576], $remote->limits, 'a file is kept whole up to its limit, the output of a command to its own');
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

    public function testAKeyOfTheServerInBetweenIsNamedSo(): void {
        self::assertSame('UNKNOWN_HOST_KEY SHA256:j changed jump', (new HostKeyRefused('SHA256:j', true, true))->getMessage());
    }
}

final class FakeRemote implements Remote {
    /** @var list<array{string, ?string, int}> */
    public array $runs = [];
    /** @var list<int> */
    public array $limits = [];

    public function __construct(private readonly Result $result, private readonly ?\Throwable $failure) {}

    public function run(string $command, ?string $stdin, int $timeout, int $keep = \Mercurius\McpSsh\SshRemote::OUTPUT_MAX): Result {
        $this->runs[] = [$command, $stdin, $timeout];
        $this->limits[] = $keep;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return $this->result;
    }
}

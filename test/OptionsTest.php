<?php

declare(strict_types=1);

namespace Mercurius\McpSsh\Test;

use Mercurius\McpSsh\Options;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {
    public function testTheServerComesFromArgumentsAndTheSecretsFromTheEnvironment(): void {
        $options = Options::from(['ssh-mcp', '--host=srv.example', '--user=deploy', '--port=2222', '--host-key=SHA256:abc'], ['SSH_PASSWORD' => 'pw']);

        self::assertSame(['srv.example', 2222, 'deploy', null, 'SHA256:abc', 'pw', null], [$options->host, $options->port, $options->user, $options->keyFile, $options->hostKey, $options->password, $options->passphrase]);
    }

    public function testWithoutAServerItStartsUnconfigured(): void {
        self::assertFalse(Options::from(['ssh-mcp'], [])->configured(), 'an install tries the server with no host');
    }

    public function testAServerInBetweenTakesTheProxyAndItsOwnSecrets(): void {
        $options = Options::from(['ssh-mcp', '--host=10.0.0.5', '--user=app', '--jump-host=bastion', '--jump-user=ops', '--jump-port=2200', '--jump-host-key=SHA256:j'], ['SSH_JUMP_PASSWORD' => 'jp', 'SSH_PROXY' => 'socks5://door:1080', 'SSH_PRIVATE_KEY' => 'KEY']);

        $jump = $options->jump;
        self::assertNotNull($jump);
        self::assertSame(['bastion', 2200, 'ops', 'SHA256:j', 'jp', 'socks5://door:1080'], [$jump->host, $jump->port, $jump->user, $jump->hostKey, $jump->password, $jump->proxy]);
        self::assertNull($options->proxy, 'the target is reached through the tunnel of the jump');
        self::assertSame('KEY', $options->privateKey);
    }

    public function testAHostAndALoginGoTogether(): void {
        $this->expectException(\InvalidArgumentException::class);

        Options::from(['ssh-mcp', '--host=srv.example'], []);
    }

    public function testAPortOutOfRangeIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);

        Options::from(['ssh-mcp', '--host=a', '--user=b', '--port=70000'], []);
    }
}

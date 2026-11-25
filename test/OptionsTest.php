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

    public function testAHostAndALoginAreNeeded(): void {
        $this->expectException(\InvalidArgumentException::class);

        Options::from(['ssh-mcp', '--host=srv.example'], []);
    }

    public function testAPortOutOfRangeIsRefused(): void {
        $this->expectException(\InvalidArgumentException::class);

        Options::from(['ssh-mcp', '--host=a', '--user=b', '--port=70000'], []);
    }
}

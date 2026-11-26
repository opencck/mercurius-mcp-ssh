<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

/** The server the MCP works with: one command at a time over one SSH connection. */
interface Remote {
    /**
     * @throws HostKeyRefused the key of the host is not the pinned one
     * @throws RemoteFailure no connection, a refused login, a command that did not end
     */
    /**
     * @param int $keep bytes of each stream kept; the rest is read, counted and cut
     */
    public function run(string $command, #[\SensitiveParameter] ?string $stdin, int $timeout, int $keep = SshRemote::OUTPUT_MAX): Result;
}

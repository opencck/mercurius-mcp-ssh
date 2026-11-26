<?php

declare(strict_types=1);

namespace Mercurius\McpSsh;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use Amp\Ssh\SshResource;

/**
 * A socket to the next server opened through an SSH connection to the server in between (ssh -J): a direct-tcpip
 * channel, the server in between resolves the name.
 */
final class TunnelConnector implements SocketConnector {
    public function __construct(private readonly SshResource $jump) {}

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket {
        $address = (string) $uri;
        $address = preg_replace('~^tcp://~', '', $address) ?? $address;
        if (preg_match('~^\[(.+)]:(\d+)$~', $address, $m) === 1 || preg_match('~^([^:]+):(\d+)$~', $address, $m) === 1) {
            return $this->jump->createTunnel($m[1], (int) $m[2]);
        }
        return $this->jump->createTunnel($address, 22);
    }
}

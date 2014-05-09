<?php

namespace Datagram;

use React\EventLoop\LoopInterface;
use React\Dns\Resolver\Resolver;
use React\Promise\When;
use Datagram\Socket;
use \Exception;

class Factory
{
    protected $loop;
    protected $resolver;

    public function __construct(LoopInterface $loop, Resolver $resolver = null)
    {
        $this->loop = $loop;
        $this->resolver = $resolver;
    }

    public function createClient($address)
    {
        $factory = $this;
        $loop = $this->loop;

        return $this->resolveAddress($address)->then(function ($address) use ($loop) {
            $socket = stream_socket_client($address, $errno, $errstr);
            if (!$socket) {
                throw new Exception('Unable to create client socket: ' . $errstr, $errno);
            }

            return new Socket($loop, $socket);
        });
    }

    public function createServer($address)
    {
        $factory = $this;
        $loop = $this->loop;

        return $this->resolveAddress($address)->then(function ($address) use ($loop) {
            $socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND);
            if (!$socket) {
                throw new Exception('Unable to create server socket: ' . $errstr, $errno);
            }

            return new Socket($loop, $socket);
        });
    }

    protected function resolveAddress($address)
    {
        if (strpos($address, '://') === false) {
            $address = 'udp://' . $address;
        }

        // parse_url() does not accept null ports (random port assignment) => manually remove
        $nullport = false;
        if (substr($address, -2) === ':0') {
            $address = substr($address, 0, -2);
            $nullport = true;
        }

        $parts = parse_url($address);

        if (!$parts || !isset($parts['host'])) {
            return When::resolve($address);
        }

        if ($nullport) {
            $parts['port'] = 0;
        }

        // remove square brackets for IPv6 addresses
        $host = trim($parts['host'], '[]');

        return $this->resolveHost($host)->then(function ($host) use ($parts) {
            $address = $parts['scheme'] . '://';

            if (isset($parts['port']) && strpos($host, ':') !== false) {
                // enclose IPv6 address in square brackets if a port will be appended
                $host = '[' . $host . ']';
            }

            $address .= $host;

            if (isset($parts['port'])) {
                $address .= ':' . $parts['port'];
            }

            return $address;
        });
    }

    protected function resolveHost($host)
    {
        // there's no need to resolve if the host is already given as an IP address
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return When::resolve($host);
        }
        // todo: remove this once the dns resolver can handle the hosts file!
        if ($host === 'localhost') {
            return When::resolve('127.0.0.1');
        }

        if ($this->resolver === null) {
            return When::reject(new Exception('No resolver given in order to get IP address for given hostname'));
        }
        return $this->resolver->resolve($host);
    }
}
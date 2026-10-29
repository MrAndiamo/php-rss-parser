<?php

declare(strict_types=1);

namespace Timvandendries\PhpRssParser\Tests\Support;

/**
 * Runs Fixtures/router.php on PHP's built-in web server on a free port of 127.0.0.1.
 */
final class FeedServer
{
    /** @var resource|null */
    private $process;

    private int $port;

    public function __construct()
    {
        $this->port = self::findFreePort();
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        // An argument array starts PHP directly, without a shell, so proc_terminate() stops the server itself.
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", dirname(__DIR__) . '/Fixtures/router.php'],
            [0 => ['file', $nullDevice, 'r'], 1 => ['file', $nullDevice, 'w'], 2 => ['file', $nullDevice, 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the PHP built-in web server');
        }
        $this->process = $process;
        $this->waitUntilListening();
    }

    public function url(string $path): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    public function stop(): void
    {
        if ($this->process !== null) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new \RuntimeException("Could not find a free port: {$errorMessage}");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.2);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(50000);
        }
        $this->stop();
        throw new \RuntimeException("The PHP built-in web server didn't start on port {$this->port}");
    }
}

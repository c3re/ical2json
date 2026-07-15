<?php

declare(strict_types=1);

/**
 * Boots a PHP built-in web server for the duration of a test run.
 *
 * Used to exercise api/index.php as a real HTTP endpoint and to serve
 * calendar fixtures from a local origin.
 */
final class HttpTestServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes = [];

    private int $port;

    private string $host = "127.0.0.1";

    /**
     * @param string               $docroot Directory to serve.
     * @param array<string,string> $env     Extra environment variables.
     */
    public function __construct(string $docroot, array $env = [])
    {
        $this->port = self::findFreePort();

        $cmd = sprintf(
            "exec php -S %s:%d -t %s",
            $this->host,
            $this->port,
            escapeshellarg($docroot)
        );

        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["file", "/dev/null", "a"],
            2 => ["file", "/dev/null", "a"],
        ];

        // Merge with the current environment so PHP keeps working paths.
        $fullEnv = array_merge($this->currentEnv(), $env);

        $process = proc_open($cmd, $descriptors, $this->pipes, null, $fullEnv);
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start PHP built-in server");
        }
        $this->process = $process;

        $this->waitUntilReady();
    }

    public function baseUrl(): string
    {
        return sprintf("http://%s:%d", $this->host, $this->port);
    }

    public function url(string $path): string
    {
        return $this->baseUrl() . "/" . ltrim($path, "/");
    }

    public function stop(): void
    {
        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            fclose($this->pipes[0]);
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    /** @return array<string,string> */
    private function currentEnv(): array
    {
        $env = getenv();
        return is_array($env) ? $env : [];
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen($this->host, $this->port, $errno, $errstr, 0.2);
            if (is_resource($conn)) {
                fclose($conn);
                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException("PHP built-in server did not become ready");
    }

    private static function findFreePort(): int
    {
        $sock = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        if (!is_resource($sock)) {
            throw new \RuntimeException("Unable to allocate a free port");
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ":") + 1);
    }
}


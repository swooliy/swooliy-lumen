<?php

namespace Swooliy\Lumen;

use Error;
use Throwable;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Swooliy\Lumen\Concern\Cachable;
use Swooliy\Lumen\Concern\InteractWithRequest;
use Swooliy\Server\AbstractHttpServer;

/**
 * Http Server  base on Swoole Http Server
 *
 * @category Http_Server
 * @package  Swooliy\Lumen
 * @author   ney <zoobile@gmail.com>
 * @license  MIT https://github.com/swooliy/swooliy-lumen/LICENSE.md
 * @link     https://github.com/swooliy/swooliy-lumen
 */
class HttpServer extends AbstractHttpServer
{
    use Cachable, InteractWithRequest;

    protected $app;

    protected $host;

    protected $port;

    protected $name;

    protected $options;

    /**
     * Construct for HttpServer class
     */
    public function __construct($app)
    {
        if (!file_exists(base_path('config/swooliy.php'))) {
            $erroInfo = <<<END
Swooliy config file not created!
you should run php artisan swooliy:config
END;
            throw new Exception($erroInfo);
        }

        if (!(config('swooliy'))) {
            $erroInfo = <<<END
Swooliy config not add!
You should add
\$app->configure('swooliy');
in bootstrap/app.php
END;
            throw new Exception($errInfo);
        }

        $this->app = $app;

        $this->host    = config('swooliy.server.host');
        $this->port    = config('swooliy.server.port');
        $this->name    = config('swooliy.server.name');
        $this->options = config('swooliy.server.options');

        parent::__construct($this->host, $this->port, $this->options);

        $this->initCache();

    }

    /**
     * Callback when swoole http server's master process created.
     *
     * @param Swoole\Http\Server $server swoole server instance
     *
     * @return void
     */
    public function onMasterStarted($server)
    {
        echo "{$this->name} server is starting at http://{$this->host}:{$this->port} on swoole\n";

        // In MacOS, swoole can't set the process name
        if (PHP_OS != 'Darwin') {
            swoole_set_process_name("{$this->name}-master");
        }
    }

    /**
     * Callback when swoole http server's manager process created.
     *
     * @param Swoole\Http\Server $server swoole server instance
     *
     * @return void
     */
    public function onManagerStarted($server)
    {
        // In MacOS, swoole can't set the process name
        if (PHP_OS != 'Darwin') {
            swoole_set_process_name("{$this->name}-manager");
        }
    }

    /**
     * Callback when swoole http server's worker process created.
     *
     * @param Swoole\Http\Server $server   swoole server instance
     * @param int                $workerId current worker proccess's pid
     *
     * @return void
     */
    public function onWorkerStarted($server, $workerId)
    {
        $this->clearCache();

        if (PHP_OS != 'Darwin') {
            swoole_set_process_name("{$this->name}-worker-{$workerId}");
        }

        // don't bootstrap lumen app in task workers
        if ($server->taskworker) {
            return;
        }

        $server->app = include base_path("bootstrap/app.php");

        // clear events instance in case of repeated listeners in worker process
        Facade::clearResolvedInstance('events');

    }

    /**
     * Clear APC or OPCache.
     *
     * @return void
     */
    protected function clearCache()
    {
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache();
        }
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Callback when swoole http server's worker process received http messages.
     *
     * @param Swoole\Http\Request  $swRequest  current swoole request instance
     * @param Swoole\Http\Response $swResponse current swoole response instance
     *
     * @return void
     */
    public function onRequest($swRequest, $swResponse)
    {
        try {
            if (config('swooliy.server.options.enable_static_handler') == true && $this->handleStatic($swRequest, $swResponse)) {
                return;
            }

            if (config('swooliy.cache.switch') == 1 && $response = $this->hasCache($swRequest)) {
                $swResponse->header("Content-Type", $response->headers->get('Content-Type') ?? "application/json");
                $swResponse->status($response->status());
                $swResponse->end($response->getContent());
                return;
            }

            $request = $this->makeIlluminateRequest($swRequest);

            $response = $this->server->app->handle($request);

            // If lumen return redirect, we should handle it
            if ($location = $response->headers->get("location")) {
                $swResponse->header('Location', $location);
                $swResponse->status($response->status());
                return;
            }

            $swResponse->header("Content-Type", $response->headers->get("Content-Type") ?? "application/json");
            $swResponse->status($response->status());
            $swResponse->end($response->getContent());
        } catch (Throwable $e) {
            $error = sprintf( 
                'onRequest: Uncaught exception "%s"([%d]%s) at %s:%s, %s%s',
                get_class($e),
                $e->getCode(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(), PHP_EOL,
                $e->getTraceAsString()
            );
            print_r($error);
            $swResponse->status(500);
            $swResponse->end('Oops! An unexpected error occurred.');
        }

    }

    /**
     * Callback when swoole http server shutdown.
     *
     * @param Swoole\Http\Server $server swoole server instance
     *
     * @return void
     */
    public function onShutdown($server)
    {
        echo "The server has shutdown.\n";

        $pidFilePath = base_path("storage/logs/pid");

        file_put_contents($pidFilePath, "");
    }

    /**
     * Callback when swoole http server's worker process stopped.
     *
     * @param Swoole\Http\Server $server   swoole server instance
     * @param int                $workerId the order number of the worker process
     *
     * @return void
     */
    public function onWorkerStopped($server, $workerId)
    {
        echo "The worker-{$workerId} has stopped.\n";
    }

    /**
     * Callback when swoole http server's worker process happen error.
     *
     * @param Swoole\Http\Server $server    swoole server instance
     * @param int                $workerId  the order number of the worker process
     * @param int                $workerPid the pid of the worker process
     * @param int                $exitCode  the status code return when the process exited
     * @param int                $signal    the signal when the process exited
     *
     * @return void
     */
    public function onWorkerError($server, $workerId, $workerPid, $exitCode, $signal)
    {
        echo "The worker-{$workerId} happend error, pid:{$workerPid}, exitCode:{$exitCode}, signal:{$signal}\n";
    }

    /**
     * Callback when swoole http server's manager process stopped.
     *
     * @param Swoole\Http\Server $server swoole server instance
     *
     * @return void
     */
    public function onManagerStopped($server)
    {
        echo "The manager process has stopped!\n";
    }

    /**
     * Check the server is running or not
     *
     * @return boolean
     */
    public static function isRunning()
    {
        $pidFilePath = base_path("storage/logs/pid");
        return file_exists($pidFilePath) && 
            !empty(file_get_contents($pidFilePath));
    }
}

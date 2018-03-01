<?php

namespace Drefined\Zipkin\Instrumentation\Laravel\Middleware;

use Closure;
use Drefined\Zipkin\Core\Annotation;
use Drefined\Zipkin\Core\BinaryAnnotation;
use Drefined\Zipkin\Core\Endpoint;
use Drefined\Zipkin\Core\Identifier;
use Drefined\Zipkin\Instrumentation\Laravel\Services\ZipkinTracingService;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class EnableZipkinTracing
{
    /**
     * The application instance.
     *
     * @var Application $app
     */
    protected $app;


    protected static $firstSpan;

    protected static $endPoint;

    /**
     * Create a new middleware instance.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  \Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            //获取基本信息
            $method    = $request->getMethod();
            $uri       = $request->getRequestUri();
            $query     = $request->query->all();
            $ipAddress = $request->server('SERVER_ADDR') ? $request->server('SERVER_ADDR') : '127.0.0.1';
            $port      = $request->server('SERVER_PORT');
            $host      = $request->server('HTTP_HOST');
            $name      = "{$method} {$uri}";

            //获取header头中的zipkin相关信息
            $traceId      = $this->processSpecialHeader($request->header('X-B3-TraceId'));
            $spanId       = $this->processSpecialHeader($request->header('X-B3-SpanId'));
            $parentSpanId = $this->processSpecialHeader($request->header('X-B3-ParentSpanId'));
            $sampled      = $request->header('X-B3-Sampled') ? $request->header('X-B3-Sampled') : 1.0;
            $debug        = $request->header('X-B3-Flags') ? $request->header('X-B3-Flags') : true;

            //获取zipkin单例
            $tracingService = app(ZipkinTracingService::class);
            $apiPrefix      = explode('/', trim(config('api.prefix'), '/'));
            $serverName     = $apiPrefix ? $apiPrefix : 'laravel';
            self::$endPoint = new Endpoint($ipAddress, $port, end($serverName));
            $tracingService->createTrace(null, self::$endPoint, $traceId, $sampled, $debug);

            $trace = $tracingService->getTrace();
            //创建span作为server
            $trace->createNewSpan($name, null, $spanId, $parentSpanId);
            $trace->record(
                [Annotation::generateServerRecv()],
                [
                    BinaryAnnotation::generateString('server.env', $this->app->environment()),
                    BinaryAnnotation::generateString('server.host', $host),
                    BinaryAnnotation::generateString('server.uri', $uri),
                    BinaryAnnotation::generateString('server.query', json_encode($query)),
                ]
            );

            //记录当前Server的SpanId
            $spans           = $trace->getSpans();
            self::$firstSpan = current($spans);
            $spanId          = current($spans)->getSpanId();

            //记录下当前的zipkin相关信息，往下传递header头需要
            config(['zipKinServerSpanId' => $spanId]);
            config(['zipKinServerTraceId' => $traceId ? $traceId : $trace->getTraceId()]);
            config(['zipKinServerParentSpanId' => $parentSpanId]);
            config(['zipKinServerSampled' => $sampled]);
            config(['zipKinServerFlags' => $debug]);
            config(['zipKinServerAddress' => $ipAddress]);
            config(['zipKinServerPort' => $port]);
            config(['zipKinServerName' => end($serverName)]);

            return $next($request);
        } catch (\Exception $e) {
            Log::error("The ZipKin Server has an error." . $e->getMessage());
            $trace->getTracer()->setDebug(false);
            return $next($request);
        }

    }

    public function terminate(Request $request, Response $response)
    {
        try {
            $tracingService = app(ZipkinTracingService::class);

            $trace = $tracingService->getTrace();
            $trace->pushSpan(self::$firstSpan);
            $trace->setEndpoint(self::$endPoint);
            $trace->record(
                [Annotation::generateServerSend()],
                [BinaryAnnotation::generateString('server.response.http_status_code', $response->getStatusCode())]
            );

        } catch (\Exception $e) {

        }
    }

    /**
     * 处理特殊的header
     * @param $header
     * @return Identifier|null
     */
    protected function processSpecialHeader($header)
    {
        return $header ? new Identifier($header) : null;
    }
}

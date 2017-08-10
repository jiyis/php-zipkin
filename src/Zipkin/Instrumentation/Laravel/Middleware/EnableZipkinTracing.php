<?php

namespace Drefined\Zipkin\Instrumentation\Laravel\Middleware;

use Closure;
use Drefined\Zipkin\Core\Annotation;
use Drefined\Zipkin\Core\BinaryAnnotation;
use Drefined\Zipkin\Core\Endpoint;
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
            $method    = $request->getMethod();
            $uri       = $request->getRequestUri();
            $query     = $request->query->all();
            $ipAddress = $request->server('SERVER_ADDR') ?? '127.0.0.1';
            $port      = $request->server('SERVER_PORT');
            $host      = $request->server('HTTP_HOST');
            $name      = "{$method} {$uri}";

            $traceId      = $request->header('X-B3-TraceId') ?? null;
            $spanId       = $request->header('X-B3-SpanId') ?? null;
            $parentSpanId = $request->header('X-B3-ParentSpanId') ?? null;
            $sampled      = $request->header('X-B3-Sampled') ?? 1.0;
            $debug        = $request->header('X-B3-Flags') ?? true;

            /** @var ZipkinTracingService $tracingService */
            $tracingService = app(ZipkinTracingService::class);
            $serverName     = explode('/', trim(config('api.prefix'), '/')) ?? 'laravel';
            self::$endPoint = new Endpoint($ipAddress, $port, end($serverName));
            $tracingService->createTrace(null, self::$endPoint, $traceId, $sampled, $debug);

            $trace = $tracingService->getTrace();
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
            $spans  = $trace->getSpans();
            $spanId = current($spans)->getSpanId();
            config(['zipKinServerSpanId' => $spanId]);
            config(['zipKinServerTraceId' => $traceId]);
            config(['zipKinServerParentSpanId' => $parentSpanId]);
            config(['zipKinServerSampled' => $sampled]);
            config(['zipKinServerFlags' => $debug]);
            config(['zipKinServerAddress' => $ipAddress]);
            config(['zipKinServerPort' => $port]);
            config(['zipKinServerName' => end($serverName)]);
            self::$firstSpan = current($spans);

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
            /** @var ZipkinTracingService $tracingService */
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
}

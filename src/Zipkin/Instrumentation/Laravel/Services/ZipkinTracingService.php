<?php
namespace Drefined\Zipkin\Instrumentation\Laravel\Services;

use Drefined\Zipkin\Core\Endpoint;
use Drefined\Zipkin\Core\Trace;
use Drefined\Zipkin\Tracer;

class ZipkinTracingService
{
    /** @var Trace $trace */
    private $trace;

    public function createTrace(
        Tracer $tracer = null,
        Endpoint $endpoint = null,
        $traceId = null,
        $sampled = 1.0,
        $debug = true
    ) {
        $this->trace = new Trace($tracer, $endpoint, $traceId, $sampled, $debug);
    }

    /**
     * @return Trace
     */
    public function getTrace(): Trace
    {
        return $this->trace;
    }
}

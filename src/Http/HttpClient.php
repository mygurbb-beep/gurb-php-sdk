<?php

declare(strict_types=1);

namespace Gurb\Http;

/**
 * The SDK's only transport dependency.
 *
 * One method, no PSR-7 objects in the signature. Why not require PSR-18
 * outright: that would drag psr/http-client, psr/http-factory and a concrete
 * message implementation into the dependency tree of every app that installs
 * us, purely so we can make one JSON call. Hosts that already run Guzzle can
 * wrap it in ten lines (see Psr18HttpClient), and tests get a real transport
 * instead of a mocked global function.
 */
interface HttpClient
{
    /**
     * Perform the request and return the response, whatever the status code.
     *
     * A 4xx or 5xx is a normal return, NOT an exception — mapping HTTP failures
     * onto GurbApiException is the SDK's job, and a transport that threw on 403
     * would take that decision away from it.
     *
     * @throws TransportException When the request never produced a response:
     *                            DNS failure, refused connection, TLS error, timeout.
     */
    public function send(HttpRequest $request): HttpResponse;
}

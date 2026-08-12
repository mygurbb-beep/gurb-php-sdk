<?php

declare(strict_types=1);

namespace Gurb\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Optional adapter for apps that already have a PSR-18 client (Guzzle,
 * Symfony HttpClient, ...).
 *
 * OPTIONAL, not required: this file only loads if you reference the class, so
 * the psr/* interfaces never have to be installed for the rest of the SDK to
 * work. Use it to inherit your app's existing proxy config, retry middleware
 * and request logging rather than running a second HTTP stack.
 *
 *     $gurb = new GurbClient($key, httpClient: new Psr18HttpClient(
 *         $container->get(ClientInterface::class),
 *         $psr17Factory,
 *         $psr17Factory,
 *     ));
 *
 * If your logging middleware dumps request headers, exclude X-Api-Key.
 */
final class Psr18HttpClient implements HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $e) {
            // PSR-18 makes no distinction between "timed out" and "refused", so
            // neither can we. Both land as NETWORK_ERROR upstream anyway.
            throw new TransportException($e->getMessage(), previous: $e);
        }

        return new HttpResponse($response->getStatusCode(), (string) $response->getBody());
    }
}

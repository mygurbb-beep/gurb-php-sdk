<?php

declare(strict_types=1);

namespace Gurb\Http;

/**
 * The default transport: ext-curl, no packages.
 *
 * ext-curl is not a composer `require` on purpose — it is only needed if you
 * use this class, and an app injecting its own HttpClient should not be blocked
 * from installing because of a transport it never touches. So the check happens
 * at construction, where the error can name the actual fix.
 */
final class CurlHttpClient implements HttpClient
{
    /**
     * @param bool $verifyTls Leave this true. It exists so a developer testing
     *                        against a self-signed staging cert has a documented
     *                        switch instead of quietly patching the SDK — the
     *                        API key travels on this connection.
     */
    public function __construct(private readonly bool $verifyTls = true)
    {
        if (!\function_exists('curl_init')) {
            throw new TransportException(
                'CurlHttpClient needs ext-curl. Install it, or pass your own HttpClient to GurbClient.',
            );
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $handle = \curl_init();
        \curl_setopt_array($handle, [
            \CURLOPT_URL => $request->url,
            \CURLOPT_CUSTOMREQUEST => $request->method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            // Header lines are not parsed: nothing in the SDK reads a response
            // header, and leaving them out of the body avoids a split step.
            \CURLOPT_HEADER => false,
            // A redirect would replay the X-Api-Key header at whatever host the
            // response names. The API never redirects; refusing to follow one
            // means a misconfigured proxy cannot walk the key somewhere else.
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            \CURLOPT_CONNECTTIMEOUT_MS => min($request->timeoutMs, 10_000),
            \CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
            \CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
        ]);

        if ($request->body !== null) {
            \curl_setopt($handle, \CURLOPT_POSTFIELDS, $request->body);
        }

        $body = \curl_exec($handle);
        $errorNumber = \curl_errno($handle);
        $errorMessage = \curl_error($handle);
        $status = (int) \curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        \curl_close($handle);

        if ($errorNumber !== 0 || !\is_string($body)) {
            throw new TransportException(
                $errorMessage !== '' ? $errorMessage : 'curl request failed',
                timedOut: $errorNumber === \CURLE_OPERATION_TIMEOUTED,
            );
        }

        return new HttpResponse($status, $body);
    }
}

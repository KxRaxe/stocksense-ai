<?php

namespace App\Services\Forecasting;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to the ML service. It is internal to the stack: only this app calls
 * it, presenting a shared secret in a header.
 *
 * What is sent and returned is defined by the JSON Schemas in `contracts/`;
 * tests check both directions against them. Failures become a
 * ForecastException whose message is fit to show; the details go to the log.
 */
class MlClient
{
    /**
     * Trains a model on the series, measures its accuracy, and forecasts.
     *
     * @param  array<string, mixed>  $payload  A forecast request (contracts/forecast-request.schema.json)
     * @return array<string, mixed> A forecast response (contracts/forecast-response.schema.json)
     *
     * @throws ForecastException
     */
    public function forecast(array $payload): array
    {
        $body = $this->post('/forecast', $payload);

        // The service is ours, but a proxy error page or a version mismatch would
        // otherwise fail much later with a confusing message.
        foreach (['model_version', 'forecasts', 'training'] as $key) {
            if (! array_key_exists($key, $body)) {
                Log::error('ML service returned an unexpected answer', ['missing' => $key]);

                throw new ForecastException('The forecasting service gave an answer this app does not understand.');
            }
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ForecastException
     */
    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->request()->post($path, $payload);
        } catch (ConnectionException $e) {
            Log::warning('ML service unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            throw new ForecastException('The forecasting service could not be reached. Check that it is running and try again.', 0, $e);
        }

        if ($response->failed()) {
            throw $this->failure($path, $response);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ForecastException('The forecasting service gave an answer this app does not understand.');
        }

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl((string) config('forecasting.ml.url'))
            ->withHeaders(['X-Internal-Token' => (string) config('forecasting.ml.token')])
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('forecasting.ml.connect_timeout'))
            ->timeout((int) config('forecasting.ml.timeout'));
    }

    private function failure(string $path, Response $response): ForecastException
    {
        Log::error('ML service refused or failed a request', [
            'path' => $path,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 2000),
        ]);

        return match (true) {
            $response->status() === 401 => new ForecastException('The forecasting service did not accept this app\'s credentials. Check that ML_INTERNAL_TOKEN matches on both sides.'),
            $response->status() === 422 => new ForecastException('The forecasting service could not use the sales data: '.$this->reason($response)),
            default => new ForecastException("The forecasting service failed (HTTP {$response->status()}). Try again; if it keeps happening, check its logs."),
        };
    }

    /**
     * The first reason the service gave for refusing a request, in a sentence.
     */
    private function reason(Response $response): string
    {
        $detail = $response->json('detail');

        if (is_array($detail) && isset($detail[0]['msg']) && is_string($detail[0]['msg'])) {
            return rtrim(preg_replace('/^Value error, /', '', $detail[0]['msg']) ?? $detail[0]['msg'], '.').'.';
        }

        return is_string($detail) ? $detail : 'it was not in the expected form.';
    }
}

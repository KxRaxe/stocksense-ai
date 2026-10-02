<?php

use App\Services\Forecasting\ForecastException;
use App\Services\Forecasting\MlClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Contracts;

beforeEach(function () {
    config([
        'forecasting.ml.url' => 'http://ml.test:8000',
        'forecasting.ml.token' => 'a-shared-secret',
    ]);

    $this->payload = Contracts::fixture('forecast-request');
});

/** The example response, as the ML service would answer. */
function mlAnswer(): array
{
    return Contracts::fixture('forecast-response');
}

describe('the request', function () {
    it('posts the payload as JSON to the ML service, with the shared secret', function () {
        Http::fake(['ml.test:8000/*' => Http::response(mlAnswer())]);

        app(MlClient::class)->forecast($this->payload);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://ml.test:8000/forecast'
            && $request->method() === 'POST'
            && $request->hasHeader('X-Internal-Token', 'a-shared-secret')
            && $request->isJson()
            && $request->data() == $this->payload);
    });

    it('asks for JSON back', function () {
        Http::fake(['ml.test:8000/*' => Http::response(mlAnswer())]);

        app(MlClient::class)->forecast($this->payload);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Accept', 'application/json'));
    });

    it('sends the example request the shared contract describes', function () {
        expect(Contracts::errors('forecast-request', $this->payload))->toBe([]);
    });
});

describe('the answer', function () {
    it('is the ML service\'s response, parsed', function () {
        Http::fake(['ml.test:8000/*' => Http::response(mlAnswer())]);

        $answer = app(MlClient::class)->forecast($this->payload);

        expect($answer['model_version'])->toBe(mlAnswer()['model_version'])
            ->and($answer['forecasts'])->toHaveCount(3)
            ->and($answer['forecasts'][0]['periods'])->toHaveCount(4);
    });

    it('is the shape the shared contract describes (the example response)', function () {
        expect(Contracts::errors('forecast-response', mlAnswer()))->toBe([]);
    });

    it('is checked against the contract: a response missing what the app needs is caught', function () {
        $broken = mlAnswer();
        unset($broken['forecasts']['0']['periods']);

        expect(Contracts::errors('forecast-response', $broken))->not->toBe([]);
    });

    it('is refused if it lacks the parts the app relies on', function (string $missing) {
        $answer = mlAnswer();
        unset($answer[$missing]);
        Http::fake(['ml.test:8000/*' => Http::response($answer)]);

        expect(fn () => app(MlClient::class)->forecast($this->payload))
            ->toThrow(ForecastException::class, 'an answer this app does not understand');
    })->with(['model_version', 'forecasts', 'training']);

    it('is refused if it is not JSON at all', function () {
        Http::fake(['ml.test:8000/*' => Http::response('<html>Bad gateway</html>', 200)]);

        expect(fn () => app(MlClient::class)->forecast($this->payload))
            ->toThrow(ForecastException::class, 'an answer this app does not understand');
    });
});

describe('when something goes wrong', function () {
    it('says the service could not be reached when it cannot be', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to ml.test port 8000'));

        expect(fn () => app(MlClient::class)->forecast($this->payload))
            ->toThrow(ForecastException::class, 'could not be reached');
    });

    it('says the credentials were not accepted on a 401', function () {
        Http::fake(['ml.test:8000/*' => Http::response(['detail' => 'Missing or invalid internal token.'], 401)]);

        expect(fn () => app(MlClient::class)->forecast($this->payload))
            ->toThrow(ForecastException::class, 'ML_INTERNAL_TOKEN');
    });

    it('passes on why the data was refused on a 422', function () {
        Http::fake(['ml.test:8000/*' => Http::response(['detail' => [
            ['type' => 'value_error', 'loc' => ['body'], 'msg' => 'Value error, 20:1: periods must be consecutive and in order (a gap or repeat at 2025-03-10)'],
        ]], 422)]);

        expect(fn () => app(MlClient::class)->forecast($this->payload))
            ->toThrow(ForecastException::class, 'could not use the sales data: 20:1: periods must be consecutive and in order (a gap or repeat at 2025-03-10).');
    });

    it('copes with a 422 whose reason is plain text', function () {
        Http::fake(['ml.test:8000/*' => Http::response(['detail' => 'No good'], 422)]);

        expect(fn () => app(MlClient::class)->forecast($this->payload))
            ->toThrow(ForecastException::class, 'could not use the sales data: No good');
    });

    it('copes with a 422 that gives no reason', function () {
        Http::fake(['ml.test:8000/*' => Http::response([], 422)]);

        expect(fn () => app(MlClient::class)->forecast($this->payload))
            ->toThrow(ForecastException::class, 'was not in the expected form');
    });

    it('reports a server error without the details', function () {
        Http::fake(['ml.test:8000/*' => Http::response('Traceback (most recent call last): secret internals', 500)]);

        try {
            app(MlClient::class)->forecast($this->payload);
            $this->fail('Expected a ForecastException.');
        } catch (ForecastException $e) {
            expect($e->getMessage())->toContain('failed (HTTP 500)')
                ->and($e->getMessage())->not->toContain('Traceback')
                ->and($e->getMessage())->not->toContain('secret internals');
        }
    });

    it('keeps the details in the log instead', function () {
        Log::spy();
        Http::fake(['ml.test:8000/*' => Http::response('Traceback: model exploded', 500)]);

        try {
            app(MlClient::class)->forecast($this->payload);
        } catch (ForecastException) {
            // Expected.
        }

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $context['status'] === 500 && str_contains($context['body'], 'model exploded'));
    });

    it('never puts the shared secret in an error', function () {
        Http::fake(['ml.test:8000/*' => Http::response('nope', 401)]);

        try {
            app(MlClient::class)->forecast($this->payload);
        } catch (ForecastException $e) {
            expect($e->getMessage())->not->toContain('a-shared-secret');
        }
    });
});

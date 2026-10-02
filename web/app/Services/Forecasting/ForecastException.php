<?php

namespace App\Services\Forecasting;

use RuntimeException;

/**
 * Something went wrong making a forecast that the person can be told about.
 * The message is written for them: no stack traces, host names or secrets.
 */
class ForecastException extends RuntimeException {}

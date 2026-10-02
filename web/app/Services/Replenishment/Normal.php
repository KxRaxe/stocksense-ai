<?php

namespace App\Services\Replenishment;

use InvalidArgumentException;

/**
 * The standard normal distribution, for turning a service level ("in stock 95%
 * of the time") into the number of standard deviations of safety stock it needs.
 */
final class Normal
{
    /**
     * The z-score for a cumulative probability: quantile(0.95) is about 1.645.
     *
     * Uses Acklam's rational approximation, whose relative error is under 1.2e-9:
     * far finer than a safety stock rounded to whole units needs.
     */
    public static function quantile(float $p): float
    {
        if ($p <= 0.0 || $p >= 1.0) {
            throw new InvalidArgumentException('A probability must be strictly between 0 and 1.');
        }

        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];

        $low = 0.02425;

        if ($p < $low) {
            $q = sqrt(-2 * log($p));
            $x = ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        } elseif ($p <= 1 - $low) {
            $q = $p - 0.5;
            $r = $q * $q;
            $x = ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
        } else {
            $q = sqrt(-2 * log(1 - $p));
            $x = -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        return $x;
    }
}

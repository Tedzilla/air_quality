<?php

declare(strict_types=1);

namespace AirQuality\Fallback;

/**
 * A secondary PM2.5 source used to fill a city's missing hours when the sources ranked above it
 * cover too little of the history window.
 */
interface HourlySource
{
    /** Short id stored in measurements.source, e.g. "openaq". */
    public function name(): string;

    /**
     * Outdoor PM2.5 sensors near a point that have reported on or after $activeSince.
     *
     * @return list<array{id: string, label: string}>
     */
    public function sensorsNear(float $lat, float $lon, \DateTimeImmutable $activeSince): array;

    /**
     * Hourly PM2.5 means for one sensor in [$from, $to), keyed by the UTC start of the hour.
     *
     * @return list<array{observed_at: string, pm25: float}>
     */
    public function hourly(string $sensorId, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}

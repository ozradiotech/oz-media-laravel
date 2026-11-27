<?php

use App\Actions\Analytics\AcquisitionMetrics;
use App\Models\Station;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Wednesday 25 Nov 2026, WIB.
    $this->travelTo(Carbon::parse('2026-11-25 12:00', 'Asia/Jakarta'));
    $this->station = Station::factory()->create();
});

function sessionFrom(string $source, string $visitor, int $minutes, ?string $campaign = null): void
{
    DB::table('audience_sessions')->insert([
        'station_id' => test()->station->id,
        'mode' => 'audio',
        'visitor' => sha1($visitor),
        'started_at' => now()->subHour(),
        'last_seen_at' => now()->subHour()->addMinutes($minutes),
        'minutes' => $minutes,
        'source' => $source,
        'utm_source' => $campaign ? strtolower($source) : null,
        'utm_medium' => $campaign ? 'story' : null,
        'utm_campaign' => $campaign,
    ]);
}

function visited(string $visitor, string $date): void
{
    DB::table('audience_visitors')->insert([
        'station_id' => test()->station->id,
        'date' => $date,
        'mode' => 'audio',
        'visitor' => sha1($visitor),
    ]);
}

it('groups sessions by where listeners came from', function () {
    sessionFrom('Instagram', 'a', 30, 'drive-time');
    sessionFrom('Instagram', 'a', 10, 'drive-time');
    sessionFrom('Instagram', 'b', 20);
    sessionFrom('Direct', 'c', 60);

    $metrics = app(AcquisitionMetrics::class);

    expect($metrics->sources(30)[0])->toBe([
        'source' => 'Instagram', 'sessions' => 3, 'people' => 2, 'hours' => 1.0, 'median_minutes' => 20.0,
    ])->and($metrics->campaigns(30))->toBe([
        ['campaign' => 'drive-time', 'source' => 'Instagram', 'medium' => 'story', 'sessions' => 2, 'people' => 1, 'hours' => 0.7],
    ]);
});

it('measures how many new listeners come back within 7 days', function () {
    visited('back', '2026-11-10');
    visited('back', '2026-11-14');
    visited('gone', '2026-11-10');
    visited('late', '2026-11-10');
    visited('late', '2026-11-20'); // Back, but after 7 days.
    visited('too-new', '2026-11-24'); // First seen under 7 days ago: not judged yet.
    visited('regular', '2026-08-01'); // A long-time listener is not new, however recent.
    visited('regular', '2026-11-10');
    visited('regular', '2026-11-12');

    expect(app(AcquisitionMetrics::class)->returnRate(7))->toBe(['cohort' => 3, 'returned' => 1, 'rate' => 33.3]);
});

it('follows each week\'s new listeners over the next weeks', function () {
    // Week of Mon 9 Nov: two new listeners; one is back the week after.
    visited('a', '2026-11-09');
    visited('b', '2026-11-11');
    visited('a', '2026-11-17');
    // Week of Mon 16 Nov: one new listener.
    visited('c', '2026-11-16');
    // First seen long before: in none of these cohorts.
    visited('regular', '2026-09-01');
    visited('regular', '2026-11-10');

    $cohorts = collect(app(AcquisitionMetrics::class)->weeklyCohorts(4))->keyBy('week');

    expect($cohorts['09 Nov – 15 Nov'])->toMatchArray(['new_listeners' => 2, 'week_1' => 50.0, 'week_2' => null])
        ->and($cohorts['16 Nov – 22 Nov'])->toMatchArray(['new_listeners' => 1, 'week_1' => null])
        ->and($cohorts['23 Nov – 29 Nov'])->toMatchArray(['new_listeners' => 0]);
});

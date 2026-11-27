<?php

namespace App\Actions\Analytics;

use App\Enums\PointReason;
use App\Models\PointTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where listeners come from (sessions by source and campaign) and whether
 * they come back (return rates, weekly cohorts). Everyone with the site's
 * player on counts, guests included (one device = one listener), WIB days.
 */
class AcquisitionMetrics
{
    private const TTL = 900;

    /**
     * Listening and watching sessions per source, most sessions first.
     *
     * @return list<array{source: string, sessions: int, people: int, hours: float, median_minutes: float}>
     */
    public function sources(int $days): array
    {
        return Cache::remember("analytics:sources:{$days}", self::TTL, function () use ($days): array {
            $sessions = DB::table('audience_sessions')
                ->where('started_at', '>=', $this->since($days))
                ->get(['source', 'visitor', 'minutes'])
                ->groupBy('source');

            return array_values($sessions->map(function ($group, $source): array {
                $minutes = $group->pluck('minutes')->map(fn (mixed $value): int => (int) $value)->sort()->values();

                return [
                    'source' => (string) $source,
                    'sessions' => $group->count(),
                    'people' => $group->pluck('visitor')->unique()->count(),
                    'hours' => round($minutes->sum() / 60, 1),
                    'median_minutes' => (float) $minutes->median(),
                ];
            })->sortByDesc('sessions')->all());
        });
    }

    /**
     * Sessions from links tagged with a utm_campaign.
     *
     * @return list<array{campaign: string, source: string, medium: string|null, sessions: int, people: int, hours: float}>
     */
    public function campaigns(int $days): array
    {
        return Cache::remember("analytics:campaigns:{$days}", self::TTL, fn (): array => array_values(DB::table('audience_sessions')
            ->where('started_at', '>=', $this->since($days))
            ->whereNotNull('utm_campaign')
            ->groupBy('utm_campaign', 'source', 'utm_medium')
            ->select([
                'utm_campaign as campaign',
                'source',
                'utm_medium as medium',
                DB::raw('COUNT(*) as sessions'),
                DB::raw('COUNT(DISTINCT visitor) as people'),
                DB::raw('SUM(minutes) as minutes'),
            ])
            ->orderByDesc('sessions')
            ->get()
            ->map(fn (object $row): array => [
                'campaign' => (string) $row->campaign,
                'source' => (string) $row->source,
                'medium' => $row->medium !== null ? (string) $row->medium : null,
                'sessions' => (int) $row->sessions,
                'people' => (int) $row->people,
                'hours' => round((int) $row->minutes / 60, 1),
            ])
            ->all()));
    }

    /**
     * Of the listeners who first tuned in at least $within days ago (within
     * the last 60 days), the share who came back within $within days.
     *
     * @return array{cohort: int, returned: int, rate: float|null}
     */
    public function returnRate(int $within): array
    {
        return Cache::remember("analytics:return-rate:{$within}", self::TTL, function () use ($within): array {
            $today = $this->today();
            $cohort = 0;
            $returned = 0;

            foreach ($this->visitDays($today->subDays(60)) as $days) {
                $first = $days[0];

                if ($first->lessThan($today->subDays(60)) || $first->greaterThan($today->subDays($within))) {
                    continue;
                }

                $cohort++;

                foreach (array_slice($days, 1) as $day) {
                    if ($day->lessThanOrEqualTo($first->addDays($within))) {
                        $returned++;

                        break;
                    }
                }
            }

            return ['cohort' => $cohort, 'returned' => $returned, 'rate' => $cohort > 0 ? round($returned / $cohort * 100, 1) : null];
        });
    }

    /**
     * New listeners per week (Monday to Sunday, WIB) and the share active in
     * each of the four weeks after; plus that week's sign-ups and the share
     * who listened (signed in) the week after. Null: that week isn't over.
     *
     * @return list<array{week: string, new_listeners: int, week_1: float|null, week_2: float|null, week_3: float|null, week_4: float|null, signups: int, signups_back: float|null}>
     */
    public function weeklyCohorts(int $weeks = 8): array
    {
        return Cache::remember("analytics:cohorts:{$weeks}", self::TTL, function () use ($weeks): array {
            $thisWeek = $this->today()->startOfWeek(CarbonImmutable::MONDAY);
            $weekIndex = fn (CarbonImmutable $day): int => (int) floor($day->startOfWeek(CarbonImmutable::MONDAY)->diffInWeeks($thisWeek, false));

            // [weeks ago => [visitor => [weeks ago active...]]]
            $cohorts = [];

            foreach ($this->visitDays($thisWeek->subWeeks($weeks - 1)) as $visitor => $days) {
                $first = $weekIndex($days[0]);

                if ($first >= $weeks) {
                    continue;
                }

                $cohorts[$first][$visitor] = array_unique(array_map($weekIndex, $days));
            }

            $rows = [];

            for ($ago = $weeks - 1; $ago >= 0; $ago--) {
                $start = $thisWeek->subWeeks($ago);
                $members = $cohorts[$ago] ?? [];
                $size = count($members);
                // Share of the cohort active N weeks later; only weeks that are
                // over count (the current week, 0, isn't).
                $retained = function (int $after) use ($ago, $members, $size): ?float {
                    $target = $ago - $after;

                    return $target < 1 || $size === 0
                        ? null
                        : round(count(array_filter($members, fn (array $active): bool => in_array($target, $active, true))) / $size * 100, 1);
                };

                [$signups, $back] = $this->signupsBack($start, $ago >= 2);

                $rows[] = [
                    'week' => $start->format('d M').' – '.$start->addDays(6)->format('d M'),
                    'new_listeners' => $size,
                    'week_1' => $retained(1),
                    'week_2' => $retained(2),
                    'week_3' => $retained(3),
                    'week_4' => $retained(4),
                    'signups' => $signups,
                    'signups_back' => $back,
                ];
            }

            return array_reverse($rows);
        });
    }

    /**
     * Sign-ups in the week starting $start, and the share of them who earned
     * listening points in the week after (null until that week is over).
     *
     * @return array{0: int, 1: float|null}
     */
    private function signupsBack(CarbonImmutable $start, bool $nextWeekOver): array
    {
        $range = fn (CarbonImmutable $from): array => [
            $from->setTimezone(config('app.timezone')),
            $from->addWeek()->subSecond()->setTimezone(config('app.timezone')),
        ];

        $signups = User::query()->doesntHave('roles')->whereBetween('created_at', $range($start))->pluck('id');

        if ($signups->isEmpty() || ! $nextWeekOver) {
            return [$signups->count(), null];
        }

        $back = PointTransaction::query()
            ->where('reason', PointReason::Listening)
            ->whereIn('user_id', $signups)
            ->whereBetween('created_at', $range($start->addWeek()))
            ->distinct()
            ->count('user_id');

        return [$signups->count(), round($back / $signups->count() * 100, 1)];
    }

    /**
     * Listening days (WIB), oldest first, all stations and modes, of the
     * listeners whose first day is on or after $firstSince. The database does
     * the filtering, so memory follows the window, not the whole retention.
     *
     * @return array<string, list<CarbonImmutable>>
     */
    private function visitDays(CarbonImmutable $firstSince): array
    {
        $days = [];
        $since = $firstSince->toDateString();
        $newcomers = DB::table('audience_visitors')->select('visitor')->groupBy('visitor')->havingRaw('MIN(date) >= ?', [$since]);

        foreach (DB::table('audience_visitors')->select('visitor', 'date')->distinct()->whereIn('visitor', $newcomers)->orderBy('date')->cursor() as $row) {
            $days[(string) $row->visitor][] = CarbonImmutable::parse((string) $row->date, DashboardMetrics::TIMEZONE);
        }

        return $days;
    }

    private function since(int $days): CarbonImmutable
    {
        return $this->today()->subDays($days - 1)->setTimezone(config('app.timezone'));
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(DashboardMetrics::TIMEZONE)->startOfDay();
    }
}

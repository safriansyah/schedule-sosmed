<?php

namespace App\Services\Crm;

use App\Enums\Intent;
use App\Enums\InteractionStatus;
use App\Enums\Sentiment;
use App\Models\Interaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The numbers that describe the inbox.
 *
 * One home for them so the dashboard and the inbox itself can never disagree —
 * an SLA breach count that reads differently on two screens is worse than no
 * count at all.
 *
 * Every method is one query. These run on page load for several roles, so a
 * per-row lookup would be felt immediately.
 */
class InboxSummary
{
    /**
     * Headline figures. `sla_breach` first because it is the only one that
     * demands action today rather than merely describing the week.
     *
     * @return array<string, int>
     */
    public function headline(int $days = 7): array
    {
        $window = $this->inbound()->where('occurred_at', '>=', now()->subDays($days));

        // One pass with conditional sums rather than five COUNT queries.
        $period = (clone $window)
            ->selectRaw('
                COUNT(*) as total,
                SUM(COALESCE(sentiment_override, sentiment) = ?) as positive,
                SUM(COALESCE(sentiment_override, sentiment) = ?) as negative,
                SUM(COALESCE(sentiment_override, sentiment) = ?) as neutral,
                SUM(intent = ?) as questions
            ', [
                Sentiment::Positive->value,
                Sentiment::Negative->value,
                Sentiment::Neutral->value,
                Intent::Question->value,
            ])
            ->first();

        $open = $this->inbound()->tap($this->openOnly(...));

        $openRow = (clone $open)
            ->selectRaw('
                COUNT(*) as open_total,
                SUM(is_urgent = 1) as urgent,
                SUM(needs_reply = 1) as needs_reply
            ')
            ->first();

        return [
            'sla_breach' => $this->breaching()->count(),
            'urgent_open' => (int) ($openRow->urgent ?? 0),
            'needs_reply' => (int) ($openRow->needs_reply ?? 0),
            'open_total' => (int) ($openRow->open_total ?? 0),
            'period_total' => (int) ($period->total ?? 0),
            'positive' => (int) ($period->positive ?? 0),
            'negative' => (int) ($period->negative ?? 0),
            'neutral' => (int) ($period->neutral ?? 0),
            'questions' => (int) ($period->questions ?? 0),
            'unclassified' => $this->inbound()->unclassified()->count(),
        ];
    }

    /**
     * Daily sentiment counts for a chart.
     *
     * Days with no comments are filled with zeros rather than skipped — a gap
     * in the x-axis reads as "quiet", which is exactly what it means, whereas
     * a missing point silently compresses the timeline.
     *
     * @return array{labels: array<int, string>, positive: array<int, int>, neutral: array<int, int>, negative: array<int, int>}
     */
    public function sentimentTrend(int $days = 14): array
    {
        $rows = $this->inbound()
            ->where('occurred_at', '>=', today()->subDays($days - 1))
            ->selectRaw('
                DATE(occurred_at) as day,
                SUM(COALESCE(sentiment_override, sentiment) = ?) as positive,
                SUM(COALESCE(sentiment_override, sentiment) = ?) as neutral,
                SUM(COALESCE(sentiment_override, sentiment) = ?) as negative
            ', [Sentiment::Positive->value, Sentiment::Neutral->value, Sentiment::Negative->value])
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $labels = $positive = $neutral = $negative = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = today()->subDays($i);
            $row = $rows->get($date->toDateString());

            $labels[] = $date->translatedFormat('d M');
            $positive[] = (int) ($row->positive ?? 0);
            $neutral[] = (int) ($row->neutral ?? 0);
            $negative[] = (int) ($row->negative ?? 0);
        }

        return compact('labels', 'positive', 'neutral', 'negative');
    }

    /**
     * The urgent items still waiting, oldest first.
     *
     * Oldest first deliberately: the newest angry comment is not the one most
     * at risk of being forgotten.
     *
     * @return Collection<int, Interaction>
     */
    public function urgentQueue(int $limit = 5): Collection
    {
        return $this->inbound()
            ->tap($this->openOnly(...))
            ->urgent()
            ->with('contact:id,code,full_name,display_name,status')
            ->orderBy('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * How the team is doing against the response targets.
     *
     * @return array{answered:int, within:int, breached:int, median_hours:?float, rate:?int}
     */
    public function slaPerformance(int $days = 30): array
    {
        $answered = $this->inbound()
            ->whereNotNull('first_response_at')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->get(['occurred_at', 'first_response_at', 'is_urgent']);

        if ($answered->isEmpty()) {
            return ['answered' => 0, 'within' => 0, 'breached' => 0, 'median_hours' => null, 'rate' => null];
        }

        $hours = [];
        $within = 0;

        foreach ($answered as $interaction) {
            $taken = $interaction->occurred_at->diffInMinutes($interaction->first_response_at) / 60;
            $hours[] = $taken;

            $target = (int) config($interaction->is_urgent ? 'crm.sla.urgent_hours' : 'crm.sla.normal_hours');

            if ($taken <= $target) {
                $within++;
            }
        }

        sort($hours);
        $middle = intdiv(count($hours), 2);

        // Median, not mean: one comment answered three weeks late would drag an
        // average into meaninglessness.
        $median = count($hours) % 2 === 0
            ? ($hours[$middle - 1] + $hours[$middle]) / 2
            : $hours[$middle];

        return [
            'answered' => $answered->count(),
            'within' => $within,
            'breached' => $answered->count() - $within,
            'median_hours' => round($median, 1),
            'rate' => (int) round($within / $answered->count() * 100),
        ];
    }

    /**
     * Open items per handler, so a manager can see who is loaded.
     *
     * @return Collection<int, object>
     */
    public function workload(): Collection
    {
        return $this->inbound()
            ->tap($this->openOnly(...))
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, COUNT(*) as total, SUM(is_urgent = 1) as urgent')
            ->groupBy('assigned_to')
            ->with('assignee:id,name')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Where the classifier and a human disagreed — the only honest measure of
     * how well it is doing, and the raw material for improving the prompt.
     *
     * @return array{corrections:int, classified:int, rate:?float, pairs:Collection<int, object>}
     */
    public function classifierAccuracy(int $days = 90): array
    {
        $since = now()->subDays($days);

        $classified = $this->inbound()
            ->whereNotNull('ai_classified_at')
            ->where('ai_classified_at', '>=', $since)
            ->count();

        $corrected = $this->inbound()
            ->whereNotNull('sentiment_override')
            ->whereColumn('sentiment_override', '!=', 'sentiment')
            ->where('override_at', '>=', $since);

        $pairs = (clone $corrected)
            ->selectRaw('sentiment as ai, sentiment_override as human, COUNT(*) as total')
            ->groupBy('sentiment', 'sentiment_override')
            ->orderByDesc('total')
            ->get();

        $corrections = (int) $pairs->sum('total');

        return [
            'corrections' => $corrections,
            'classified' => $classified,
            'rate' => $classified > 0 ? round(($classified - $corrections) / $classified * 100, 1) : null,
            'pairs' => $pairs,
        ];
    }

    /* -----------------------------------------------------------------
     | Internals
     * ----------------------------------------------------------------- */

    private function inbound(): Builder
    {
        return Interaction::query()->inbound();
    }

    private function openOnly(Builder $query): void
    {
        $query->whereIn('status', [
            InteractionStatus::New->value,
            InteractionStatus::InProgress->value,
        ]);
    }

    /** Urgent, unanswered, and already past its response target. */
    private function breaching(): Builder
    {
        return $this->inbound()
            ->tap($this->openOnly(...))
            ->urgent()
            ->whereNull('first_response_at')
            ->where('occurred_at', '<', now()->subHours((int) config('crm.sla.urgent_hours')));
    }
}

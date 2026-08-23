<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Models\AccountMedia;
use App\Models\Content;
use App\Models\SocialAccount;
use App\Repositories\ContentRepository;
use App\Services\Analytics\MetricsComparison;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    /** key => [label, days] */
    public const PERIODS = [
        'today' => ['Hari ini', 1],
        '7days' => ['7 hari', 7],
        '30days' => ['30 hari', 30],
        '90days' => ['90 hari', 90],
        'year' => ['1 tahun', 365],
    ];

    public function __construct(
        private readonly ContentRepository $contents,
        private readonly MetricsComparison $comparison,
    ) {}

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    public function window(string $period): array
    {
        [$label, $days] = self::PERIODS[$period] ?? self::PERIODS['30days'];

        return [today()->subDays($days - 1), today()->endOfDay(), $label];
    }

    /**
     * Workflow counters for the window, each with the previous window as a
     * baseline so the cards can show movement rather than a bare number.
     *
     * @return array<string, array{label:string,icon:string,tone:string,current:int,previous:int,status:?string}>
     */
    public function workflow(Carbon $from, Carbon $to): array
    {
        $length = $from->diffInDays($to) + 1;
        $prevFrom = $from->copy()->subDays($length);
        $prevTo = $from->copy()->subDay()->endOfDay();

        $cards = [
            ['total', 'Total Konten', 'file-text', 'violet', null],
            ['draft', 'Draft', 'edit', 'sky', ContentStatus::Draft],
            ['waiting_approval', 'Menunggu Approval', 'clock', 'amber', ContentStatus::WaitingApproval],
            ['revision', 'Revisi', 'rotate', 'pink', ContentStatus::Revision],
            ['waiting_verification', 'Menunggu Verifikasi', 'badge-check', 'cyan', ContentStatus::WaitingVerification],
            ['scheduled', 'Terjadwal', 'calendar', 'indigo', ContentStatus::Scheduled],
            ['published', 'Terbit', 'send', 'emerald', ContentStatus::Published],
            ['failed', 'Gagal / Batal', 'alert', 'rose', ContentStatus::Failed],
        ];

        $out = [];

        foreach ($cards as [$key, $label, $icon, $tone, $status]) {
            $out[$key] = [
                'label' => $label,
                'icon' => $icon,
                'tone' => $tone,
                'status' => $status?->value,
                'current' => $this->countCreated($from, $to, $status),
                'previous' => $this->countCreated($prevFrom, $prevTo, $status),
            ];
        }

        return $out;
    }

    private function countCreated(Carbon $from, Carbon $to, ?ContentStatus $status): int
    {
        return Content::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($status, fn ($q) => $q->where('status', $status->value))
            ->count();
    }

    /** Content created per day in the window, split into published vs the rest. */
    public function contentTrend(Carbon $from, Carbon $to): array
    {
        $rows = Content::query()
            ->selectRaw('DATE(created_at) as day, status, COUNT(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day', 'status')
            ->get();

        $labels = [];
        $created = [];
        $published = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $forDay = $rows->where('day', $key);

            $labels[] = $date->translatedFormat('d M');
            $created[] = (int) $forDay->sum('total');
            $published[] = (int) $forDay->where('status', ContentStatus::Published->value)->sum('total');
        }

        return ['labels' => $labels, 'created' => $created, 'published' => $published];
    }

    /** Status distribution for the donut chart (all time). */
    public function statusBreakdown(): Collection
    {
        $counts = $this->contents->countsByStatus();

        return collect(ContentStatus::cases())
            ->filter(fn (ContentStatus $s) => $counts[$s->value] > 0)
            ->map(fn (ContentStatus $s) => [
                'label' => $s->label(),
                'value' => $counts[$s->value],
                'color' => $s->color(),
            ])
            ->values();
    }

    /** Who produced what in the window — the director's team view. */
    public function teamOutput(Carbon $from, Carbon $to, int $limit = 6): Collection
    {
        return Content::query()
            ->selectRaw('created_by, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as published', [ContentStatus::Published->value])
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('created_by')
            ->groupBy('created_by')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('creator:id,name')
            ->get();
    }

    /** Per-account performance for the same window. */
    public function accountPerformance(string $period): Collection
    {
        return SocialAccount::active()->get()->map(function (SocialAccount $account) use ($period) {
            $result = $this->comparison->forAccount($account, $period === 'today' ? 'today' : 'week');

            return [
                'account' => $account,
                'followers' => $result['metrics']['followers_total']['current'],
                'growth' => $result['metrics']['followers'],
                'likes' => $result['metrics']['likes'],
                'views' => $result['metrics']['views'],
                'posts' => AccountMedia::where('social_account_id', $account->id)->count(),
            ];
        });
    }

    public function upcoming(int $limit = 5): Collection
    {
        return $this->contents->upcoming($limit);
    }
}

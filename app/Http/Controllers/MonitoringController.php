<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\AccountMedia;
use App\Models\MediaComment;
use App\Models\SocialAccount;
use App\Services\Analytics\MetricsComparison;
use App\Services\Publishing\InstagramProfileLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Live performance of the connected accounts and every post on them —
 * including posts this app did not publish itself.
 */
class MonitoringController extends Controller
{
    /** key => [label, order-by expression] */
    private const SORTS = [
        'recent' => ['Terbaru', 'account_media.posted_at DESC'],
        'oldest' => ['Terlama', 'account_media.posted_at ASC'],
        'likes' => ['Like terbanyak', 'COALESCE(m.likes, 0) DESC'],
        'comments' => ['Komentar terbanyak', 'COALESCE(m.comments, 0) DESC'],
        'views' => ['Views terbanyak', 'COALESCE(m.views, 0) DESC'],
        'reach' => ['Reach terbanyak', 'COALESCE(m.reach, 0) DESC'],
    ];

    public function __construct(private readonly MetricsComparison $comparison) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewMonitoring->value);

        $accounts = SocialAccount::active()->orderBy('name')->get();
        $account = $accounts->firstWhere('id', $request->input('account')) ?? $accounts->first();

        $period = $request->input('period', 'week');
        [$from, $to] = $this->customRange($request);

        $comparison = $account
            ? $this->comparison->forAccount($account, $period, $from, $to)
            : null;

        $trend = $account
            ? $this->comparison->dailySeries($account, $this->comparison->periodDays($period, $from, $to))
            : ['labels' => [], 'series' => []];

        $posts = $account ? $this->posts($request, $account) : null;

        $recentComments = $account
            ? MediaComment::whereHas('media', fn ($q) => $q->where('social_account_id', $account->id))
                ->with('media:id,thumbnail_url,caption,product_type')
                ->whereNotNull('text')
                ->latest('commented_at')
                ->limit(8)
                ->get()
            : collect();

        return view('monitoring.index', [
            'accounts' => $accounts,
            'account' => $account,
            'comparison' => $comparison,
            'totals' => $account ? $this->comparison->currentTotals($account) : null,
            'trend' => $trend,
            'posts' => $posts,
            'recentComments' => $recentComments,
            'period' => $period,
            'periods' => MetricsComparison::PERIODS,
            'sorts' => self::SORTS,
            'filters' => $request->only('period', 'from', 'to', 'account', 'q', 'type', 'sort', 'origin'),
        ]);
    }

    /**
     * Posts list — searchable, filterable and sortable so an account with
     * a thousand posts stays usable.
     *
     * The latest snapshot is joined in SQL rather than loaded per row, so the
     * query count stays flat no matter how many posts are shown.
     */
    private function posts(Request $request, SocialAccount $account)
    {
        $latest = DB::table('media_metrics')
            ->selectRaw('account_media_id, MAX(captured_at) as captured_at')
            ->groupBy('account_media_id');

        $sort = array_key_exists($request->input('sort'), self::SORTS)
            ? $request->input('sort')
            : 'recent';

        return AccountMedia::query()
            ->where('account_media.social_account_id', $account->id)
            ->leftJoinSub($latest, 'l', 'l.account_media_id', '=', 'account_media.id')
            ->leftJoin('media_metrics as m', fn ($join) => $join
                ->on('m.account_media_id', '=', 'account_media.id')
                ->on('m.captured_at', '=', 'l.captured_at'))
            ->select('account_media.*')
            // selectRaw, not addSelect([...]): an array of DB::raw values does
            // not produce aliases, which silently leaves every stat null.
            ->selectRaw(
                'COALESCE(m.likes, 0) as stat_likes,'
                .'COALESCE(m.comments, 0) as stat_comments,'
                .'COALESCE(m.views, 0) as stat_views,'
                .'COALESCE(m.reach, 0) as stat_reach'
            )
            ->when($request->input('q'), fn ($q, $term) => $q->where('caption', 'like', '%'.$term.'%'))
            ->when($request->input('type'), fn ($q, $type) => $q->where('product_type', $type))
            ->when($request->input('origin') === 'app', fn ($q) => $q->whereNotNull('content_id'))
            ->when($request->input('origin') === 'external', fn ($q) => $q->whereNull('content_id'))
            ->orderByRaw(self::SORTS[$sort][1])
            ->paginate(12)
            ->withQueryString();
    }

    /** One post, with its full snapshot history. */
    public function show(AccountMedia $media): View
    {
        $this->authorize(Permission::ViewMonitoring->value);

        $media->load('account', 'content');

        $snapshots = $media->metrics()->orderBy('captured_at')->get();

        return view('monitoring.show', [
            'media' => $media,
            'snapshots' => $snapshots,
            'latest' => $snapshots->last(),
            'growth' => $this->growthWindows($snapshots),
            'comments' => $media->comments()->paginate(15),
        ]);
    }

    /** Public profile card for a commenter, fetched on demand from the viewer. */
    public function profile(string $username, InstagramProfileLookup $lookup): View
    {
        $this->authorize(Permission::ViewMonitoring->value);

        $username = ltrim($username, '@');

        // Their comments on posts we actually monitor.
        $comments = MediaComment::where('username', $username)
            ->whereHas('media')
            ->with('media:id,thumbnail_url,caption,product_type')
            ->whereNotNull('text')
            ->latest('commented_at')
            ->limit(30)
            ->get();

        return view('monitoring.profile', [
            'username' => $username,
            'profile' => $lookup->find($username),
            'comments' => $comments,
        ]);
    }

    /** Every comment across an account's posts — searchable and paginated. */
    public function comments(Request $request): View
    {
        $this->authorize(Permission::ViewMonitoring->value);

        $accounts = SocialAccount::active()->orderBy('name')->get();
        $account = $accounts->firstWhere('id', $request->input('account')) ?? $accounts->first();

        $comments = $account
            ? MediaComment::whereHas('media', fn ($q) => $q->where('social_account_id', $account->id))
                ->with('media:id,thumbnail_url,caption,product_type')
                ->when($request->input('q'), fn ($q, $term) => $q->where(fn ($sub) => $sub
                    ->where('text', 'like', "%{$term}%")
                    ->orWhere('username', 'like', "%{$term}%")
                    ->orWhere('full_name', 'like', "%{$term}%")))
                ->whereNotNull('text')
                ->latest('commented_at')
                ->paginate(30)
                ->withQueryString()
            : null;

        return view('monitoring.comments', [
            'accounts' => $accounts,
            'account' => $account,
            'comments' => $comments,
            'filters' => $request->only('account', 'q'),
        ]);
    }

    /**
     * Growth over the last hour / day / week for a single post, derived from
     * consecutive snapshots.
     *
     * @return array<string, array<string, int>>
     */
    private function growthWindows($snapshots): array
    {
        $latest = $snapshots->last();

        if (! $latest) {
            return [];
        }

        $metrics = ['likes', 'comments', 'views', 'reach', 'saves', 'shares'];
        $windows = ['Per jam' => 1, 'Per hari' => 24, 'Per minggu' => 168];
        $out = [];

        foreach ($windows as $label => $hours) {
            $baseline = $snapshots
                ->filter(fn ($s) => $s->captured_at?->lte($latest->captured_at->copy()->subHours($hours)))
                ->last();

            // No snapshot that far back yet — nothing to compare against.
            if (! $baseline) {
                continue;
            }

            $out[$label] = collect($metrics)
                ->mapWithKeys(fn (string $m) => [$m => max(0, $latest->{$m} - $baseline->{$m})])
                ->all();
        }

        return $out;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function customRange(Request $request): array
    {
        if ($request->input('period') !== 'custom') {
            return [null, null];
        }

        return [
            $request->date('from') ?? today()->subDays(6),
            $request->date('to') ?? today(),
        ];
    }
}

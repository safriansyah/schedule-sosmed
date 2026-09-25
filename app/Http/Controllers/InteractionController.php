<?php

namespace App\Http\Controllers;

use App\Enums\FollowUpAction;
use App\Enums\FollowUpOutcome;
use App\Enums\Intent;
use App\Enums\InteractionStatus;
use App\Enums\InteractionType;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\Sentiment;
use App\Enums\SocialPlatform;
use App\Http\Requests\BulkInteractionRequest;
use App\Http\Requests\ManualInteractionRequest;
use App\Jobs\ClassifyInteractions;
use App\Models\Interaction;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AI\ClassifierManager;
use App\Support\PhoneNumber;
use App\Services\Crm\ContactResolver;
use App\Services\Crm\InboxSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The unified inbox: every comment and DM, what the classifier made of it, and
 * what staff did about it.
 */
class InteractionController extends Controller
{
    /**
     * Saved views. Each is one question a specific person asks first thing in
     * the morning, so they are tabs rather than a filter the user must build.
     *
     * key => [label, icon, badge tone, description]
     *
     *
     * The description is not decoration. "Tugas Saya" is not self-evident —
     * someone coming back after a week cannot tell whether it means what they
     * wrote, what they were given, or what they replied to. It is rendered as
     * a tooltip and as a line under the tab bar.
     */
    public const TABS = [
        'urgent' => ['Negatif Urgent', 'flame', 'rose',
            'Komentar negatif atau mendesak yang belum selesai ditangani.'],
        'question' => ['Pertanyaan', 'help-circle', 'blue',
            'Interaksi yang dinilai AI sebagai pertanyaan dan belum selesai.'],
        'needs_reply' => ['Perlu Dibalas', 'reply', 'amber',
            'Ditandai perlu jawaban, tapi belum dibalas siapa pun.'],
        'mine' => ['Tugas Saya', 'user', 'cyan',
            'Interaksi yang ditugaskan kepada Anda dan masih berjalan — bukan yang Anda balas, melainkan yang menjadi tanggung jawab Anda.'],
        'assigned' => ['Tugas Petugas', 'users', 'indigo',
            'Tugas seluruh petugas yang masih berjalan, lengkap dengan nama pemegangnya. Untuk memantau beban kerja tim.'],
        'all' => ['Semua', 'inbox', 'slate',
            'Seluruh komentar dan DM yang masuk, apa pun statusnya.'],
        'done' => ['Selesai', 'check-circle', 'emerald',
            'Sudah dibalas, diselesaikan, atau diabaikan. Arsip, bukan antrean.'],
    ];

    public function __construct(
        private readonly ActivityLogger $log,
        private readonly InboxSummary $summary,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewInteractions->value);

        $tabs = $this->visibleTabs($request->user());
        $tab = array_key_exists($request->input('tab'), $tabs) ? $request->input('tab') : 'urgent';

        $interactions = $this->query($request, $tab)
            ->with(['contact:id,code,full_name,display_name,status,phone_e164', 'assignee:id,name', 'ticket:id,interaction_id,number'])
            ->paginate(25)
            ->withQueryString();

        return view('interactions.index', [
            'interactions' => $interactions,
            'tab' => $tab,
            'tabs' => $tabs,
            'counts' => $this->tabCounts(),
            'stats' => $this->summary->headline(),
            'channels' => $this->channelOptions(),
            'sentiments' => Sentiment::options(),
            'intents' => Intent::options(),
            'statuses' => InteractionStatus::options(),
            'assignees' => $this->assignableUsers(),
            'filters' => $request->only('tab', 'q', 'channel', 'sentiment', 'intent', 'status', 'handler'),
            'canSeeAllTasks' => $request->user()->hasPermission(Permission::ViewAllInteractions),
        ]);
    }

    public function show(Interaction $interaction): View
    {
        $this->authorize(Permission::ViewInteractions->value);

        $interaction->load([
            'contact.identities', 'contact.region', 'assignee', 'resolver', 'overrider',
            'source', 'ticket:id,interaction_id,number,status',
        ]);

        // Everything else this person has said to us — the context that decides
        // whether one bad comment is an outlier or a pattern.
        $history = $interaction->contact
            ? Interaction::where('contact_id', $interaction->contact_id)
                ->whereKeyNot($interaction->getKey())
                ->latest('occurred_at')
                ->limit(10)
                ->get()
            : collect();

        // Berapa yang tidak muat, supaya panelnya bisa menawarkan daftar penuh
        // alih-alih berhenti diam di sepuluh baris.
        $historyTotal = $interaction->contact
            ? Interaction::where('contact_id', $interaction->contact_id)
                ->whereKeyNot($interaction->getKey())
                ->count()
            : 0;

        return view('interactions.show', [
            'interaction' => $interaction,
            'history' => $history,
            'historyTotal' => $historyTotal,
            'sentiments' => Sentiment::options(),
            // The shared <x-contact-form> lives in this page's sidebar, so it
            // needs the same option list the contact page gives it.
            'owners' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * A human correcting the classifier.
     *
     * The machine's answer is preserved alongside the correction rather than
     * overwritten: the pairs are the only honest measure of how well the
     * classifier is doing, and the raw material for improving the prompt.
     */
    public function override(Request $request, Interaction $interaction): RedirectResponse
    {
        $this->authorize(Permission::HandleInteractions->value);

        $data = $request->validate([
            'sentiment' => ['required', 'string', 'in:'.implode(',', array_keys(Sentiment::options()))],
            'is_urgent' => ['nullable', 'boolean'],
        ]);

        $interaction->forceFill([
            'sentiment_override' => $data['sentiment'],
            'override_by' => $request->user()->id,
            'override_at' => now(),
            'is_urgent' => (bool) ($data['is_urgent'] ?? $interaction->is_urgent),
        ])->save();

        $this->log->log(
            'interaction.override',
            'Mengoreksi hasil klasifikasi AI',
            $interaction,
            ['ai' => $interaction->sentiment?->value, 'manusia' => $data['sentiment']],
        );

        return back()->with('success', 'Koreksi tersimpan. Terima kasih — ini dipakai untuk memperbaiki akurasi.');
    }

    /**
     * Match this comment to a contact, from the page.
     *
     * The resolver already ran for every synced comment; a row lands here when
     * it arrived before the CRM existed, or when the match failed. Telling the
     * operator to go and run `php artisan contacts:resolve` is not an
     * instruction they can act on, so the same service gets a button.
     */
    public function resolveContact(Interaction $interaction, ContactResolver $resolver): RedirectResponse
    {
        $this->authorize(Permission::HandleInteractions->value);

        if ($interaction->contact_id !== null) {
            return back()->with('info', 'Interaksi ini sudah tercocokkan ke kontak.');
        }

        $contact = $resolver->resolveFor($interaction);

        if ($contact === null) {
            return back()->withErrors([
                'contact' => 'Tidak bisa mencocokkan otomatis — data pengirim tidak cukup. Catat manual lewat Database Kontak.',
            ]);
        }

        $this->log->log(
            'interaction.contact_resolved',
            "Mencocokkan interaksi ke kontak {$contact->code}",
            $interaction,
            ['contact_id' => $contact->getKey()],
        );

        return back()->with('success', "Tercocokkan ke kontak {$contact->code}.");
    }

    /**
     * How well the classifier is actually doing.
     *
     * Built entirely from the corrections staff make: when a human overrides a
     * verdict we keep BOTH answers, and the disagreements are the only honest
     * measure available — there is no labelled ground truth otherwise. An empty
     * page here means nobody has disagreed yet, which is information too.
     */
    public function accuracy(Request $request): View
    {
        $this->authorize(Permission::ViewInteractions->value);

        $days = (int) $request->input('days', 90);
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 90;

        return view('interactions.accuracy', [
            'days' => $days,
            'accuracy' => $this->summary->classifierAccuracy($days),
            'corrections' => Interaction::whereNotNull('sentiment_override')
                ->whereColumn('sentiment_override', '!=', 'sentiment')
                ->where('override_at', '>=', now()->subDays($days))
                ->with('overrider:id,name')
                ->latest('override_at')
                ->paginate(20),
        ]);
    }

    /** The form for logging something no API can fetch. */
    public function createManual(): View
    {
        $this->authorize(Permission::HandleInteractions->value);

        return view('interactions.manual', [
            'channels' => ManualInteractionRequest::channelOptions(),
            'types' => InteractionType::options(),
        ]);
    }

    /**
     * Record an interaction by hand.
     *
     * TikTok exposes no DM API at all, and WhatsApp needs an approved business
     * account — so for those channels an operator typing it in is not a
     * workaround, it is the intended path. Everything downstream (contact
     * resolution, classification, follow-up, SLA) then treats it exactly like
     * a synced comment.
     */
    public function storeManual(ManualInteractionRequest $request, ContactResolver $resolver): RedirectResponse
    {
        $data = $request->validated();
        $phone = PhoneNumber::normalize($data['phone'] ?? null);

        if (filled($data['phone'] ?? null) && $phone === null) {
            return back()->withInput()->withErrors([
                'phone' => 'Nomor tidak dikenali sebagai nomor Indonesia yang valid.',
            ]);
        }

        // validated() omits absent nullable keys, so a phone-only entry has no
        // author_handle at all — not an empty one.
        $handle = filled($data['author_handle'] ?? null) ? $data['author_handle'] : $phone;

        $interaction = Interaction::create([
            'channel' => $data['channel'],
            'type' => $data['type'],
            'direction' => 'inbound',
            // Namespaced so a hand-typed row can never collide with a real
            // platform id, while still satisfying the unique key.
            'external_id' => 'manual-'.Str::uuid(),
            'author_handle' => $handle,
            'author_name' => $data['author_name'] ?? null,
            'text' => $data['text'],
            'occurred_at' => $data['occurred_at'] ?? now(),
            'status' => InteractionStatus::New->value,
        ]);

        // Attach it to a person the same way the sync does, so a manual entry
        // shows up in that contact's history alongside their comments.
        $contact = $phone
            ? $resolver->resolveByPhone($phone, $data['author_name'] ?? null)
            : $resolver->resolveFor($interaction);

        if ($contact) {
            $interaction->forceFill(['contact_id' => $contact->id])->save();
        }

        $this->log->log('interaction.manual', 'Mencatat interaksi manual', $interaction);

        return redirect()
            ->route('interactions.show', $interaction)
            ->with('success', 'Interaksi dicatat. Klasifikasi akan berjalan pada penilaian berikutnya.');
    }

    /**
     * Apply one action to a whole selection.
     *
     * At a hundred comments a day most of the inbox is short praise that needs
     * no reply, so clearing it one row at a time is the bottleneck this
     * removes. Written as a single UPDATE per action rather than a loop of
     * model saves — the audit entry records the batch, not each row.
     */
    public function bulk(BulkInteractionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $ids = $data['ids'];
        $user = $request->user();

        if ($data['action'] === 'reclassify') {
            // Clearing the stamp is the whole mechanism: the scheduled run
            // picks up anything unclassified. Deliberately NOT dispatched to
            // the queue — that would depend on a worker being up, and the
            // scheduler already runs the classifier in-process every 15
            // minutes. "Nilai sekarang" remains the immediate option.
            Interaction::whereIn('id', $ids)->update(['ai_classified_at' => null]);

            $this->log->log('interaction.bulk', 'Menilai ulang '.count($ids).' interaksi', null, ['ids' => $ids]);

            return back()->with('success', count($ids).' interaksi akan dinilai ulang pada penilaian berikutnya.');
        }

        if ($data['action'] === 'assign') {
            $this->authorize(Permission::AssignInteractions->value);

            Interaction::whereIn('id', $ids)->update([
                'assigned_to' => $data['assigned_to'],
                // Assigning something nobody has touched starts the clock on it.
                'status' => InteractionStatus::InProgress->value,
            ]);

            $name = User::find($data['assigned_to'])?->name ?? 'petugas';

            $this->log->log('interaction.bulk', "Menugaskan {$name} pada ".count($ids).' interaksi', null, ['ids' => $ids]);

            return back()->with('success', count($ids)." interaksi ditugaskan ke {$name}.");
        }

        $status = match ($data['action']) {
            'in_progress' => InteractionStatus::InProgress,
            'done' => InteractionStatus::Done,
            'ignore' => InteractionStatus::Ignored,
        };

        $changes = ['status' => $status->value];

        // Only stamp a resolution on rows that do not already have one, so a
        // bulk tidy-up never rewrites who actually closed something earlier.
        if (! $status->isOpen()) {
            Interaction::whereIn('id', $ids)->whereNull('resolved_at')->update([
                'resolved_at' => now(),
                'resolved_by' => $user->id,
            ]);
        }

        Interaction::whereIn('id', $ids)->update($changes);

        $this->log->log(
            'interaction.bulk',
            'Mengubah status '.count($ids).' interaksi menjadi '.$status->label(),
            null,
            ['ids' => $ids, 'status' => $status->value],
        );

        return back()->with('success', count($ids).' interaksi ditandai '.mb_strtolower($status->label()).'.');
    }

    /** Run the classifier now instead of waiting for the schedule. */
    public function classify(Request $request, ClassifierManager $classifier): RedirectResponse
    {
        $this->authorize(Permission::HandleInteractions->value);

        $stats = app(ClassifyInteractions::class)->handle($classifier);

        if ($stats['classified'] === 0) {
            return back()->with('info', 'Tidak ada interaksi baru yang perlu dinilai.');
        }

        return back()->with('success', sprintf(
            '%d interaksi dinilai — %d mendesak. (%d dari cache, %d dari AI)',
            $stats['classified'], $stats['urgent'], $stats['from_cache'], $stats['from_llm'],
        ));
    }

    /* -----------------------------------------------------------------
     | Query building
     * ----------------------------------------------------------------- */

    private function query(Request $request, string $tab)
    {
        return Interaction::query()
            ->inbound()
            ->tap(fn ($q) => $this->applyTab($q, $tab))
            ->search($request->input('q'))
            ->when($request->input('channel'), fn ($q, $v) => $q->where('channel', $v))
            ->when($request->input('intent'), fn ($q, $v) => $q->where('intent', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            // Isolate one person's workload. Guarded by the oversight
            // permission: without it, this would be a way to read a
            // colleague's queue by editing the URL.
            ->when(
                $request->filled('handler') && $request->user()?->hasPermission(Permission::ViewAllInteractions),
                fn ($q) => $q->where('assigned_to', (int) $request->input('handler')),
            )
            // The override wins wherever it exists, so filtering must look at
            // both columns or a corrected item disappears from its own tab.
            ->when($request->input('sentiment'), fn ($q, $v) => $q->where(fn ($sub) => $sub
                ->where('sentiment_override', $v)
                ->orWhere(fn ($inner) => $inner->whereNull('sentiment_override')->where('sentiment', $v))))
            ->orderByDesc('is_urgent')
            ->orderByDesc('occurred_at')
            // Pembeda terakhir — lihat ContactController::index().
            ->orderBy('id');
    }

    /**
     * The tabs this user may actually open.
     *
     * A tab they cannot use is removed rather than shown-and-refused: a
     * supervisor view in an operator's tab bar is a promise the app will not
     * keep. Removing it here also means `?tab=assigned` typed by hand falls
     * back to the default instead of leaking everyone's workload.
     *
     * @return array<string, array<int, string>>
     */
    private function visibleTabs(User $user): array
    {
        if ($user->hasPermission(Permission::ViewAllInteractions)) {
            return self::TABS;
        }

        return array_diff_key(self::TABS, ['assigned' => null]);
    }

    /**
     * The working queues exclude anything that already became a ticket: that
     * comment has been picked up, and its work now lives in ticketing. "Semua"
     * and "Selesai" still show everything, so nothing is ever hidden outright.
     */
    private function applyTab($query, string $tab): void
    {
        match ($tab) {
            'urgent' => $query->urgent()->open()->withoutTicket(),
            'question' => $query->where('intent', Intent::Question->value)->open()->withoutTicket(),
            'needs_reply' => $query->where('needs_reply', true)->open()->withoutTicket(),
            'mine' => $query->where('assigned_to', auth()->id())->open()->withoutTicket(),
            // Everyone's live workload. Unassigned rows are deliberately out:
            // they belong to the queues above, not to anybody yet.
            'assigned' => $query->whereNotNull('assigned_to')->open()->withoutTicket(),
            'done' => $query->whereIn('status', [
                InteractionStatus::Replied->value,
                InteractionStatus::Done->value,
                InteractionStatus::Ignored->value,
            ]),
            default => $query,
        };
    }

    /**
     * Counts for the tab badges.
     *
     * @return array<string, int>
     */
    private function tabCounts(): array
    {
        // Same shape as applyTab(), or the badge would promise work the tab
        // does not actually contain.
        $open = Interaction::inbound()->open()->withoutTicket();

        return [
            'urgent' => (clone $open)->urgent()->count(),
            'question' => (clone $open)->where('intent', Intent::Question->value)->count(),
            'needs_reply' => (clone $open)->where('needs_reply', true)->count(),
            'mine' => (clone $open)->where('assigned_to', auth()->id())->count(),
            'assigned' => (clone $open)->whereNotNull('assigned_to')->count(),
            'all' => Interaction::inbound()->count(),
            'done' => 0,   // a closed pile is not a to-do; no badge
        ];
    }

    /** Only channels that actually have data — an empty filter option is noise. */
    private function channelOptions(): array
    {
        // DB::table, not the model: Eloquent casts `channel` to a SocialPlatform,
        // and the filter needs the raw string values for the <option> tags.
        return DB::table('interactions')
            ->distinct()
            ->pluck('channel')
            ->mapWithKeys(fn (string $value) => [
                $value => SocialPlatform::tryFrom($value)?->label() ?? ucfirst($value),
            ])
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function assignableUsers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', [
                RoleName::Manager->value, RoleName::Pic->value,
                RoleName::Operator->value, RoleName::SuperAdmin->value,
            ]))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Http\Requests\StoreContentRequest;
use App\Http\Requests\UpdateContentRequest;
use App\Models\Content;
use App\Models\SocialAccount;
use App\Models\User;
use App\Repositories\ContentRepository;
use App\Services\ContentService;
use App\Services\WorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class ContentController extends Controller
{
    public function __construct(
        private readonly ContentService $service,
        private readonly ContentRepository $repository,
        private readonly WorkflowService $workflow,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Content::class);

        return view('contents.index', [
            'contents' => $this->repository->paginate($request->only('status', 'q', 'creator', 'from', 'to')),
            'counts' => $this->repository->countsByStatus(),
            'creators' => User::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('status', 'q', 'creator', 'from', 'to'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Content::class);

        return view('contents.create', [
            'accounts' => SocialAccount::active()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreContentRequest $request): RedirectResponse
    {
        $content = $this->service->create(
            $request->validated(),
            $request->file('media', []),
            $request->user(),
        );

        return redirect()
            ->route('contents.show', $content)
            ->with('toast', ['message' => 'Draft berhasil dibuat.', 'type' => 'success']);
    }

    public function show(Content $content): View
    {
        $this->authorize('view', $content);

        $content->load([
            'media', 'creator', 'curator', 'verifier',
            'schedules.account',
            'approvals.curator', 'verifications.verifier', 'revisions.requester',
        ]);

        return view('contents.show', compact('content'));
    }

    public function edit(Content $content): View
    {
        $this->authorize('update', $content);

        return view('contents.edit', [
            'content' => $content->load('media', 'schedules'),
            'accounts' => SocialAccount::active()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateContentRequest $request, Content $content): RedirectResponse
    {
        $this->service->update(
            $content,
            $request->validated(),
            $request->file('media', []),
            $request->input('remove_media', []),
            $request->user(),
        );

        return redirect()
            ->route('contents.show', $content)
            ->with('toast', ['message' => 'Konten diperbarui.', 'type' => 'success']);
    }

    public function destroy(Content $content): RedirectResponse
    {
        $this->authorize('delete', $content);

        $this->service->delete($content, request()->user());

        return redirect()
            ->route('contents.index')
            ->with('toast', ['message' => 'Konten dihapus.', 'type' => 'success']);
    }

    /** Send a draft into the approval queue. */
    public function submit(Content $content): RedirectResponse
    {
        $this->authorize('submit', $content);

        try {
            $this->workflow->submit($content, request()->user());
        } catch (RuntimeException $e) {
            return back()->with('toast', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return back()->with('toast', [
            'message' => 'Konten dikirim untuk approval.',
            'type' => 'success',
        ]);
    }

    /**
     * Put a failed content back in the queue.
     *
     * Publishing gives up after MAX_ATTEMPTS; without this there is no way to
     * retry from the UI once the cause (bad token, unreachable media) is fixed.
     */
    public function retry(Content $content): RedirectResponse
    {
        $this->authorize('retry', $content);

        $content->schedules()
            ->whereNot('status', ContentStatus::Published->value)
            ->update([
                'status' => ContentStatus::Scheduled,
                'attempts' => 0,
                'last_attempt_at' => null,
                'last_error' => null,
            ]);

        $content->forceFill([
            'status' => ContentStatus::Scheduled,
            'last_error' => null,
        ])->save();

        return back()->with('toast', [
            'message' => 'Konten dikembalikan ke antrean terbit.',
            'type' => 'success',
        ]);
    }

    /** Cancel content before it goes out. */
    public function cancel(Content $content): RedirectResponse
    {
        $this->authorize('cancel', $content);

        try {
            $this->workflow->cancel($content, request()->user(), request('note'));
        } catch (RuntimeException $e) {
            return back()->with('toast', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return back()->with('toast', ['message' => 'Konten dibatalkan.', 'type' => 'info']);
    }
}

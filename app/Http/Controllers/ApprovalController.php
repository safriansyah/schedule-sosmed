<?php

namespace App\Http\Controllers;

use App\Enums\ApprovalAction;
use App\Enums\Permission;
use App\Http\Requests\ApprovalDecisionRequest;
use App\Models\Content;
use App\Repositories\ContentRepository;
use App\Services\WorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class ApprovalController extends Controller
{
    public function __construct(
        private readonly ContentRepository $repository,
        private readonly WorkflowService $workflow,
    ) {}

    /** Queue of content waiting for a curator decision. */
    public function index(): View
    {
        $this->authorize(Permission::ViewApproval->value);

        return view('approvals.index', [
            'contents' => $this->repository->approvalQueue(),
            'history' => $this->repository->approvalHistoryFor(request()->user()),
        ]);
    }

    /** Record the curator's decision and move the content along. */
    public function store(ApprovalDecisionRequest $request, Content $content): RedirectResponse
    {
        $actor = $request->user();
        $note = $request->input('note');

        try {
            $message = match ($request->action()) {
                ApprovalAction::Approved => $this->approved($content, $actor, $note, $request->suggestions()),
                ApprovalAction::Rejected => $this->rejected($content, $actor, $note),
                ApprovalAction::Revision => $this->revision($content, $actor, $note),
            };
        } catch (RuntimeException $e) {
            return back()->with('toast', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return redirect()
            ->route('approvals.index')
            ->with('toast', ['message' => $message, 'type' => 'success']);
    }

    private function approved(Content $content, $actor, ?string $note, array $suggestions): string
    {
        $this->workflow->approve($content, $actor, $note, $suggestions);

        return 'Konten disetujui dan diteruskan ke verifikasi.';
    }

    private function rejected(Content $content, $actor, ?string $note): string
    {
        $this->workflow->reject($content, $actor, (string) $note);

        return 'Konten ditolak.';
    }

    private function revision(Content $content, $actor, ?string $note): string
    {
        $this->workflow->requestRevision($content, $actor, (string) $note);

        return 'Permintaan revisi dikirim ke tim creative.';
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\VerificationDecisionRequest;
use App\Models\Content;
use App\Models\Verification;
use App\Repositories\ContentRepository;
use App\Services\WorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class VerificationController extends Controller
{
    public function __construct(
        private readonly ContentRepository $repository,
        private readonly WorkflowService $workflow,
    ) {}

    /** Queue of content that passed approval and awaits the final check. */
    public function index(): View
    {
        $this->authorize(Permission::ViewVerification->value);

        return view('verifications.index', [
            'contents' => $this->repository->verificationQueue(),
            'checklist' => Verification::CHECKLIST,
            'history' => $this->repository->verificationHistoryFor(request()->user()),
        ]);
    }

    public function store(VerificationDecisionRequest $request, Content $content): RedirectResponse
    {
        $actor = $request->user();
        $note = $request->input('note');

        try {
            if ($request->input('action') === 'approved') {
                $this->workflow->verify($content, $actor, $request->checklist(), $note);
                $message = 'Konten terverifikasi dan siap terbit.';
            } else {
                $this->workflow->requestRevision($content, $actor, (string) $note);
                $message = 'Permintaan revisi dikirim ke tim creative.';
            }
        } catch (RuntimeException $e) {
            return back()->with('toast', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return redirect()
            ->route('verifications.index')
            ->with('toast', ['message' => $message, 'type' => 'success']);
    }
}

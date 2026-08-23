<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Enums\Permission;
use App\Models\CalendarEvent;
use App\Models\Content;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(): View
    {
        $this->authorize(Permission::ViewCalendar->value);

        return view('calendar.index', [
            'statuses' => ContentStatus::cases(),
            'creators' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Feed consumed by FullCalendar. It asks for a window (start/end) whenever
     * the user navigates, so only the visible range is queried.
     */
    public function events(Request $request): JsonResponse
    {
        $this->authorize(Permission::ViewCalendar->value);

        $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after:start'],
            'status' => ['nullable', 'string'],
            'creator' => ['nullable', 'integer'],
        ]);

        $from = $request->date('start');
        $to = $request->date('end');

        $contents = Content::with('creator:id,name')
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$from, $to])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('creator'), fn ($q, $id) => $q->where('created_by', $id))
            ->get()
            ->map(fn (Content $content) => [
                'id' => $content->id,
                'title' => $content->title,
                'start' => $content->scheduled_at->toIso8601String(),
                'url' => route('contents.show', $content),
                'backgroundColor' => $content->status->color(),
                'borderColor' => $content->status->color(),
                'extendedProps' => [
                    'kind' => 'content',
                    'status' => $content->status->label(),
                    'creator' => $content->creator?->name,
                ],
            ]);

        // Notes are pinned by users and are not filtered by content status.
        $notes = CalendarEvent::with('user:id,name')
            ->between($from, $to)
            ->when($request->input('creator'), fn ($q, $id) => $q->where('user_id', $id))
            ->get()
            ->map(fn (CalendarEvent $event) => [
                'id' => 'note-'.$event->id,
                'title' => $event->title,
                'start' => $event->starts_at->toIso8601String(),
                'end' => $event->ends_at?->toIso8601String(),
                'allDay' => $event->is_all_day,
                'backgroundColor' => $event->displayColor(),
                'borderColor' => $event->displayColor(),
                'extendedProps' => [
                    'kind' => 'note',
                    'noteId' => $event->id,
                    'type' => $event->typeLabel(),
                    'note' => $event->note,
                    'author' => $event->user?->name,
                    'editable' => $request->user()->isSuperAdmin() || $event->user_id === $request->user()->id,
                ],
            ]);

        return response()->json($contents->concat($notes)->values());
    }

    /**
     * Drag & drop reschedule. Only users who may manage the calendar and edit
     * the content itself can move it.
     */
    public function move(Request $request, Content $content): JsonResponse
    {
        $this->authorize(Permission::ManageCalendar->value);
        $this->authorize('update', $content);

        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        $content->update(['scheduled_at' => $data['scheduled_at']]);
        $content->schedules()->update(['scheduled_at' => $data['scheduled_at']]);

        return response()->json([
            'message' => 'Jadwal diperbarui.',
            'scheduled_at' => $content->scheduled_at->toIso8601String(),
        ]);
    }
}

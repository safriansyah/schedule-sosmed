<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\CalendarEventRequest;
use App\Models\CalendarEvent;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notes, reminders and deadlines a user pins to the calendar. These live
 * alongside scheduled content and are what the director uses to flag dates
 * to the team without touching any content.
 */
class CalendarEventController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function store(CalendarEventRequest $request): JsonResponse
    {
        $event = new CalendarEvent;

        $event->fill($request->safe()->only('title', 'type', 'note', 'starts_at', 'ends_at', 'is_all_day'));
        $event->user_id = $request->user()->id;   // author is never taken from input
        $event->save();

        $this->log->log(
            'calendar.note_created',
            "Catatan kalender \"{$event->title}\" ditambahkan",
            $event,
            ['type' => $event->type, 'starts_at' => $event->starts_at->toIso8601String()],
        );

        return response()->json(['message' => 'Catatan ditambahkan.', 'event' => $this->present($event)], 201);
    }

    public function update(CalendarEventRequest $request, CalendarEvent $event): JsonResponse
    {
        $event->fill($request->safe()->only('title', 'type', 'note', 'starts_at', 'ends_at', 'is_all_day'))->save();

        $this->log->log('calendar.note_updated', "Catatan \"{$event->title}\" diperbarui", $event);

        return response()->json(['message' => 'Catatan diperbarui.', 'event' => $this->present($event)]);
    }

    public function destroy(Request $request, CalendarEvent $event): JsonResponse
    {
        $this->authorize(Permission::ManageCalendarNotes->value);

        abort_unless(
            $request->user()->isSuperAdmin() || $event->user_id === $request->user()->id,
            403,
            'Anda hanya dapat menghapus catatan sendiri.',
        );

        $this->log->log('calendar.note_deleted', "Catatan \"{$event->title}\" dihapus", $event);
        $event->delete();

        return response()->json(['message' => 'Catatan dihapus.']);
    }

    /** @return array<string, mixed> */
    private function present(CalendarEvent $event): array
    {
        return [
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
            ],
        ];
    }
}

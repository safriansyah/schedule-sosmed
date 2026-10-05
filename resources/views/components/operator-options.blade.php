{{-- The operator picker's <option>s, grouped by role. Shared by the ticket
     assign forms and the student hand-out on /students/unsigned, which both
     get their list from User::ticketHandlerOptions().

     selected: the id to pre-select.
     suffix:   optional fn(User): string appended after the name (workload). --}}
@props(['operators', 'selected' => null, 'suffix' => null])

@foreach ($operators->groupBy(fn ($u) => $u->role?->label ?? 'Lainnya') as $roleLabel => $group)
    <optgroup label="{{ $roleLabel }}">
        @foreach ($group as $operator)
            <option value="{{ $operator->id }}" @selected((string) $selected === (string) $operator->id)>
                {{ $operator->name }}{{ $suffix ? ' — '.$suffix($operator) : '' }}
            </option>
        @endforeach
    </optgroup>
@endforeach

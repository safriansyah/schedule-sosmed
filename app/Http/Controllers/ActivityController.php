<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewActivity->value);

        $activities = Activity::with('user:id,name')
            ->when($request->input('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->input('action'), fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('activities.index', [
            'activities' => $activities,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('user', 'action'),
        ]);
    }
}

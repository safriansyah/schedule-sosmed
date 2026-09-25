<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\TicketCategory;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ticket categories and their sub-categories — one self-referential table,
 * with the two-level depth enforced here rather than in the schema.
 */
class TicketCategoryController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function index(): View
    {
        $this->authorize(Permission::ManageTicketCategories->value);

        return view('tickets.categories', [
            'categories' => TicketCategory::roots()
                ->ordered()
                ->with(['children' => fn ($q) => $q->withCount('tickets')])
                ->withCount('tickets')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(Permission::ManageTicketCategories->value);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer', 'exists:ticket_categories,id'],
            'description' => ['nullable', 'string', 'max:512'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [], ['name' => 'nama']);

        // Two levels only: a sub-category may not itself have a parent.
        if (! empty($data['parent_id']) && TicketCategory::find($data['parent_id'])?->isSubCategory()) {
            return back()->withErrors(['parent_id' => 'Sub kategori tidak bisa punya sub kategori lagi.'])->withInput();
        }

        $category = TicketCategory::create($data + ['slug' => $data['name']]);

        $this->log->log('ticket_category.created', "Menambah kategori tiket “{$category->name}”", $category);

        return back()->with('success', 'Kategori ditambahkan.');
    }

    public function update(Request $request, TicketCategory $category): RedirectResponse
    {
        $this->authorize(Permission::ManageTicketCategories->value);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'nama']);

        $category->update($data + ['is_active' => (bool) ($data['is_active'] ?? false)]);

        $this->log->log('ticket_category.updated', "Mengubah kategori tiket “{$category->name}”", $category);

        return back()->with('success', 'Kategori diperbarui.');
    }

    /**
     * Categories are deactivated, not deleted, once they have tickets:
     * removing one would leave historic tickets pointing at nothing, and a
     * closed ticket must still read correctly years later.
     */
    public function destroy(TicketCategory $category): RedirectResponse
    {
        $this->authorize(Permission::ManageTicketCategories->value);

        $inUse = $category->tickets()->exists()
            || $category->children()->whereHas('tickets')->exists();

        if ($inUse) {
            $category->update(['is_active' => false]);

            $this->log->log('ticket_category.deactivated', "Menonaktifkan kategori “{$category->name}”", $category);

            return back()->with('success', 'Kategori masih dipakai tiket, jadi dinonaktifkan (bukan dihapus).');
        }

        $name = $category->name;
        $category->delete();

        $this->log->log('ticket_category.deleted', "Menghapus kategori tiket “{$name}”");

        return back()->with('success', 'Kategori dihapus.');
    }
}

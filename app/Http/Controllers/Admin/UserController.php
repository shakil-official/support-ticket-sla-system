<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Team management: role, availability and skills (categories) of every account.
 * `role` is not mass assignable on User, so it can only be changed here, by an administrator.
 */
class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users', [
            'users' => User::query()->with('categories')->orderBy('role')->orderBy('name')->paginate(25),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'roles' => UserRole::cases(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $role = UserRole::from($request->validated('role'));

        // An administrator can never remove their own admin rights (no accidental lock-out).
        if ($user->is($request->user()) && $role !== UserRole::Admin) {
            return back()->with('error', 'You cannot change your own role.');
        }

        $user->role = $role;
        $user->is_available = $request->boolean('is_available');
        $user->save();

        // Skills only matter for agents.
        $user->categories()->sync($role === UserRole::Agent ? ($request->validated('categories') ?? []) : []);

        return back()->with('status', "{$user->name} updated.");
    }
}

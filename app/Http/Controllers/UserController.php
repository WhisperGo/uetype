<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;

/**
 * Intended admin/user management (show/update user profiles). Currently a stub —
 * public profiles are served by ProfileController.
 */
class UserController extends Controller
{
    public function show($id)
    {
        // TODO: Show user profile and stats
    }

    public function update(Request $request, $id)
    {
        // TODO: Update user profile
    }

    public function inventory()
    {
        // TODO: Show user items
    }
}

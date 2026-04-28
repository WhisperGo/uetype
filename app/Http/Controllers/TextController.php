<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Text;

class TextController extends Controller
{
    public function index()
    {
        // TODO: List all texts (Admin)
    }

    public function store(Request $request)
    {
        // TODO: Add new text (Admin)
    }

    public function update(Request $request, $id)
    {
        // TODO: Update a text (Admin)
    }

    public function destroy($id)
    {
        // TODO: Delete a text (Admin)
    }
}

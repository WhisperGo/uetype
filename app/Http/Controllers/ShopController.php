<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;

/**
 * Intended to list and purchase shop items (cosmetics bought with coins).
 * Currently a stub.
 */
class ShopController extends Controller
{
    public function index()
    {
        // TODO: List items available in shop
    }

    public function purchase(Request $request, $id)
    {
        // TODO: Buy an item
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Item;
use App\Models\UserItem;

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

<?php

namespace App\Http\Controllers;

use App\Services\AchievementService;
use App\Support\AchievementDefinitions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Renders the Achievements page, listing earned and locked achievements.
 */
class AchievementController extends Controller
{
    /**
     * The Achievements page. Achievements are evaluated STATICALLY when the page
     * loads (no background jobs) from existing typing_results + users data.
     */
    public function index(Request $request, AchievementService $service): View
    {
        $user = $request->user();

        $result = $service->evaluate($user);

        return view('achievements.index', [
            'achievements' => $result['items'],
            'earnedCount' => $result['earned_count'],
            'total' => $result['total'],
            'categories' => AchievementDefinitions::categories(),
        ]);
    }
}

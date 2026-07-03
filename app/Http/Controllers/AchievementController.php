<?php

namespace App\Http\Controllers;

use App\Services\AchievementService;
use App\Support\AchievementDefinitions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class AchievementController extends Controller
{
    /**
     * Halaman Achievements. Achievement dihitung STATIS saat halaman dibuka
     * (tidak ada job latar belakang) dari data typing_results + users existing.
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

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Matches;
use App\Models\Text;
use App\Models\MatchParticipant;
use App\Models\KeystrokeLog;

/**
 * Intended to manage typing matches (create, store results). Currently a stub —
 * multiplayer flow lives in the MultiplayerLobby Livewire component.
 */
class MatchController extends Controller
{
    public function create()
    {
        // TODO: Prepare a typing match, fetch text based on mode/difficulty
    }

    public function store(Request $request)
    {
        // TODO: Save match results (WPM, accuracy, etc.)
    }

    public function saveKeystrokes(Request $request)
    {
        // TODO: Save detailed keystroke logs for heatmap/analysis
    }
}

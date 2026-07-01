<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Sama seperti About & Privacy: statis, tidak ada reactive state. Dibuat
 * sebagai Livewire full-page component supaya {{ $slot }} di
 * layouts/app.blade.php terisi lewat #[Layout(...)] di bawah.
 */
#[Layout('layouts.app')]
class Terms extends Component
{
    public function render()
    {
        $sections = [
            [
                'title' => '1. Acceptance of terms',
                'body'  => 'By creating an account or using uetype, you agree to these terms. If you don\'t agree, please don\'t use the service.',
            ],
            [
                'title' => '2. Your account',
                'body'  => 'You sign in with Google and choose a public nickname. You\'re responsible for keeping your account secure and for all activity under it.',
            ],
            [
                'title' => '3. Acceptable use',
                'body'  => 'Don\'t use bots, scripts, or automated input to inflate your WPM or leaderboard rank. Don\'t harass other players, impersonate someone else, or attempt to disrupt Multiplayer matches for other users.',
            ],
            [
                'title' => '4. Leaderboards & fair play',
                'body'  => 'We may reset, adjust, or remove scores that appear to violate fair play rules, and may suspend accounts found cheating.',
            ],
            [
                'title' => '5. Service availability',
                'body'  => 'uetype is a student project provided "as is," without guarantees of uptime, and features (including Ghost mode) may change or be added over time.',
            ],
            [
                'title' => '6. Termination',
                'body'  => 'You may delete your account anytime in Settings. We may suspend accounts that violate these terms.',
            ],
            [
                'title' => '7. Changes to these terms',
                'body'  => 'We may update these terms as uetype evolves. Continued use after changes means you accept the updated terms.',
            ],
            [
                'title' => '8. Contact',
                'body'  => 'Questions about these terms? Reach the team through the project repository.',
            ],
        ];

        return view('livewire.terms', compact('sections'));
    }
}
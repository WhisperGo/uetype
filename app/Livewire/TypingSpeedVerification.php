<?php

namespace App\Livewire;

use App\Services\TypingSpeedVerificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class TypingSpeedVerification extends Component
{
    #[Locked]
    public string $challengeText = '';

    #[Locked]
    public string $challengeToken = '';

    #[Locked]
    public ?int $attemptId = null;

    #[Locked]
    public string $language = 'en';

    public string $state = 'ready';

    public int $promotedCount = 0;

    public float $verifiedWpm = 0.0;

    public int $retryAfter = 0;

    public function mount(TypingSpeedVerificationService $verification): mixed
    {
        if (! $verification->eligibleResultFor(Auth::user())) {
            return $this->redirect(route('typing'));
        }

        return null;
    }

    public function startChallenge(TypingSpeedVerificationService $verification): bool
    {
        $userId = (int) Auth::id();
        $cooldownKey = "typing-verification-cooldown:{$userId}";
        $rateKey = "typing-verification-start:{$userId}";

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $this->retryAfter = RateLimiter::availableIn($cooldownKey);
            $this->state = 'cooldown';

            return false;
        }

        if (RateLimiter::tooManyAttempts($rateKey, 3)) {
            $this->retryAfter = RateLimiter::availableIn($rateKey);
            $this->state = 'rate_limited';

            return false;
        }

        RateLimiter::hit($rateKey, 3600);
        $issued = $verification->issue(Auth::user());

        $this->attemptId = $issued['attempt']->id;
        $this->challengeText = $issued['attempt']->challenge_text;
        $this->challengeToken = $issued['token'];
        $this->language = $issued['attempt']->language;
        $this->state = 'running';
        $this->retryAfter = 0;

        return true;
    }

    /** @param array<int, mixed> $events */
    public function submitEvents(array $events, string $token, TypingSpeedVerificationService $verification): array
    {
        if ($this->attemptId === null || ! hash_equals($this->challengeToken, $token)) {
            $this->state = 'failed';

            return ['passed' => false, 'reason' => 'failed'];
        }

        $result = $verification->complete(Auth::user(), $this->attemptId, $token, $events);

        $this->challengeToken = '';
        $this->challengeText = '';

        if ($result['passed']) {
            $this->state = 'passed';
            $this->promotedCount = $result['promoted_count'];
            $this->verifiedWpm = $result['wpm'];
        } else {
            $this->state = $result['reason'] === 'attempt_expired' ? 'expired' : 'failed';
            RateLimiter::hit('typing-verification-cooldown:'.Auth::id(), 30);
        }

        // The browser only needs a neutral outcome. Detailed reason codes stay in the
        // attempt metadata/logs so the response cannot become a tuning oracle.
        return [
            'passed' => $result['passed'],
            'reason' => $result['reason'] === 'attempt_expired' ? 'expired' : ($result['passed'] ? 'passed' : 'failed'),
        ];
    }

    public function render()
    {
        return view('livewire.typing-speed-verification');
    }
}

<?php

namespace App\Livewire;

use App\Services\TypingSpeedVerificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
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
        $rateKey = "typing-verification-start:{$userId}";

        // Normal retries are immediate. This generous ceiling only stops automated request
        // flooding and can never produce the previous hour-long wait after three attempts.
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            $this->retryAfter = RateLimiter::availableIn($rateKey);
            $this->state = 'rate_limited';

            return false;
        }

        RateLimiter::hit($rateKey, 60);
        $issued = $verification->issue(Auth::user());

        $this->attemptId = $issued['attempt']->id;
        $this->challengeText = $issued['attempt']->challenge_text;
        $this->challengeToken = $issued['token'];
        $this->language = $issued['attempt']->language;
        $this->state = 'running';
        $this->retryAfter = 0;

        return true;
    }

    #[Renderless]
    public function beginChallenge(string $token, TypingSpeedVerificationService $verification): bool
    {
        if ($this->attemptId === null || ! hash_equals($this->challengeToken, $token)) {
            return false;
        }

        return $verification->begin(Auth::user(), $this->attemptId, $token);
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

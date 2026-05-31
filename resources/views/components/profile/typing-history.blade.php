<?php

use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $filterMode = 'all';

    public function with(): array
    {
        $user = auth()->user();

        $query = $user->matchParticipants()->with('match');

        if ($this->filterMode !== 'all') {
            $query->whereHas('match', function ($q) {
                $q->where('mode_played', $this->filterMode);
            });
        }

        // Aggregate stats (based on all matches, not filtered, or filtered? Let's do filtered stats)
        $statsQuery = clone $query;
        $totalMatches = $statsQuery->count();
        $averageWpm = $totalMatches > 0 ? $statsQuery->avg('wpm') : 0;
        $averageAccuracy = $totalMatches > 0 ? $statsQuery->avg('accuracy') : 0;

        $history = $query->latest('created_at')->paginate(5);

        return [
            'history' => $history,
            'totalMatches' => $totalMatches,
            'averageWpm' => round($averageWpm, 2),
            'averageAccuracy' => round($averageAccuracy, 2),
        ];
    }

    public function updatedFilterMode()
    {
        $this->resetPage();
    }
};
?>

<section class="space-y-6">
    <header class="flex justify-between items-center">
        <div>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                {{ __('Typing History') }}
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                {{ __('Review your recent typing test performances and statistics.') }}
            </p>
        </div>
        <div>
            <select wire:model.live="filterMode" class="border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm">
                <option value="all">All Modes</option>
                <option value="wordlist">Wordlist</option>
                <option value="quote">Quote</option>
                <option value="code">Code</option>
            </select>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col items-center justify-center shadow-sm">
            <span class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Matches</span>
            <span class="text-3xl font-bold text-indigo-600 dark:text-indigo-400 mt-2">{{ $totalMatches }}</span>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col items-center justify-center shadow-sm">
            <span class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Average WPM</span>
            <span class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2">{{ $averageWpm }}</span>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 flex flex-col items-center justify-center shadow-sm">
            <span class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Average Accuracy</span>
            <span class="text-3xl font-bold text-amber-500 dark:text-amber-400 mt-2">{{ $averageAccuracy }}%</span>
        </div>
    </div>

    <div class="overflow-hidden bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg border border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-900/50 dark:text-gray-300">
                    <tr>
                        <th scope="col" class="px-6 py-3">Date</th>
                        <th scope="col" class="px-6 py-3">Match Type</th>
                        <th scope="col" class="px-6 py-3">Mode</th>
                        <th scope="col" class="px-6 py-3">WPM</th>
                        <th scope="col" class="px-6 py-3">Accuracy</th>
                        <th scope="col" class="px-6 py-3">Placement</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $participant)
                        <tr class="bg-white dark:bg-gray-800 border-b dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                {{ $participant->created_at->format('d M Y, H:i') }}
                            </td>
                            <td class="px-6 py-4 capitalize font-medium text-gray-900 dark:text-gray-200">
                                {{ str_replace('_', ' ', $participant->match->match_type) }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded dark:bg-blue-900 dark:text-blue-300">
                                    {{ ucfirst($participant->match->mode_played) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 font-bold text-green-600 dark:text-green-400">
                                {{ $participant->wpm }}
                            </td>
                            <td class="px-6 py-4 text-amber-600 dark:text-amber-500 font-semibold">
                                {{ $participant->accuracy }}%
                            </td>
                            <td class="px-6 py-4">
                                @if($participant->match->match_type === 'solo_practice')
                                    <span class="text-gray-400">-</span>
                                @else
                                    #{{ $participant->placement ?: '-' }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                No typing history found for the selected mode.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    
    @if($history->hasPages())
        <div class="mt-4">
            {{ $history->links() }}
        </div>
    @endif
</section>
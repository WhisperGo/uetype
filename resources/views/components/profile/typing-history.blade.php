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

        $query = $user->typingResults();

        if ($this->filterMode !== 'all') {
            $query->where('mode', $this->filterMode);
        }

        // Aggregate statistics from the filtered results.
        $statsQuery = clone $query;
        $totalMatches = $statsQuery->count();
        $averageWpm = $totalMatches > 0 ? $statsQuery->avg('net_wpm') : 0;
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
    <header class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
        <div>
            <h2 class="font-mono text-lg font-semibold text-foreground">
                {{ __('history.title') }}
            </h2>
            <p class="mt-1 text-sm text-muted">
                {{ __('history.subtitle') }}
            </p>
        </div>
        <div>
            <select wire:model.live="filterMode" class="bg-background/60 border-white/10 text-foreground focus:border-brand focus:ring-brand rounded-lg shadow-sm font-mono text-sm">
                <option value="all">{{ __('history.filter_all') }}</option>
                <option value="time">Time</option>
                <option value="words">Words</option>
                <option value="survival">Survival</option>
                <option value="ghost">Ghost</option>
            </select>
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div class="bg-surface/40 border border-white/5 rounded-2xl p-4 flex flex-col items-center justify-center">
            <span class="text-xs font-mono font-semibold text-muted uppercase tracking-[0.15em]">{{ __('history.total_tests') }}</span>
            <span class="text-2xl sm:text-3xl font-bold font-mono text-brand-bright tabular-nums mt-2">{{ $totalMatches }}</span>
        </div>
        <div class="bg-surface/40 border border-white/5 rounded-2xl p-4 flex flex-col items-center justify-center">
            <span class="text-xs font-mono font-semibold text-muted uppercase tracking-[0.15em]">{{ __('history.avg_wpm') }}</span>
            <span class="text-2xl sm:text-3xl font-bold font-mono text-gold mt-2">{{ $averageWpm }}</span>
        </div>
        <div class="bg-surface/40 border border-white/5 rounded-2xl p-4 flex flex-col items-center justify-center">
            <span class="text-xs font-mono font-semibold text-muted uppercase tracking-[0.15em]">{{ __('history.avg_accuracy') }}</span>
            <span class="text-2xl sm:text-3xl font-bold font-mono text-gold mt-2">{{ $averageAccuracy }}%</span>
        </div>
    </div>

    <div class="overflow-hidden bg-surface/40 border border-white/5 rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="text-xs text-muted uppercase tracking-wider font-mono bg-white/5">
                    <tr>
                        <th scope="col" class="px-3 sm:px-6 py-3 font-semibold">{{ __('history.th_date') }}</th>
                        <th scope="col" class="px-3 sm:px-6 py-3 font-semibold">{{ __('history.th_mode') }}</th>
                        <th scope="col" class="px-3 sm:px-6 py-3 font-semibold">{{ __('history.th_wpm') }}</th>
                        <th scope="col" class="px-3 sm:px-6 py-3 font-semibold">{{ __('history.th_raw') }}</th>
                        <th scope="col" class="px-3 sm:px-6 py-3 font-semibold">{{ __('history.th_accuracy') }}</th>
                        <th scope="col" class="px-3 sm:px-6 py-3 font-semibold">{{ __('history.th_duration') }}</th>
                    </tr>
                </thead>
                <tbody class="font-mono">
                    @forelse ($history as $result)
                        <tr class="border-t border-white/5 hover:bg-white/5 transition-colors">
                            <td class="px-3 sm:px-6 py-4 whitespace-nowrap text-muted">
                                @localtime($result->created_at, 'd M Y, H:i')
                            </td>
                            <td class="px-3 sm:px-6 py-4">
                                <span class="bg-brand/15 text-brand-bright text-xs font-mono font-medium px-2.5 py-0.5 rounded capitalize">
                                    {{ ucfirst($result->mode?->value ?? '-') }}{{ $result->mode_config ? ' '.$result->mode_config : '' }}
                                </span>
                            </td>
                            <td class="px-3 sm:px-6 py-4 font-bold text-brand-bright tabular-nums">
                                {{ $result->net_wpm }}
                            </td>
                            <td class="px-3 sm:px-6 py-4 text-muted">
                                {{ $result->raw_wpm }}
                            </td>
                            <td class="px-3 sm:px-6 py-4 text-gold font-semibold">
                                {{ $result->accuracy }}%
                            </td>
                            <td class="px-3 sm:px-6 py-4 text-muted">
                                {{ rtrim(rtrim(number_format($result->duration_seconds, 1), '0'), '.') }}s
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-muted font-mono">
                                {{ __('history.empty') }}
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

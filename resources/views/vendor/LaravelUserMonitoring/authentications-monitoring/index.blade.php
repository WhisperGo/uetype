{{-- Authentications monitoring table: user, action type (login/logout), IP, platform, and timestamp per event. --}}
@extends('LaravelUserMonitoring::layouts.master')

@section('title', __('monitoring.tab.authentications'))

@php
    // See the note in visits-monitoring/index.blade.php: the vendor controller doesn't
    // eager load the user relation, and lazy loading is disabled outside production.
    $authentications->loadMissing('user');
@endphp

@section('content')
    <div class="overflow-x-auto -mx-2 sm:mx-0">
        <table class="w-full whitespace-nowrap text-small font-mono">
            <thead>
                <tr class="text-x-small uppercase tracking-wider text-muted border-b border-border">
                    <th class="text-left font-medium px-4 py-3">{{ __('monitoring.column.user') }}</th>
                    <th class="text-left font-medium px-4 py-3">{{ __('monitoring.column.action') }}</th>
                    <th class="text-left font-medium px-4 py-3">{{ __('monitoring.column.ip') }}</th>
                    <th class="text-left font-medium px-4 py-3">{{ __('monitoring.column.platform') }}</th>
                    <th class="text-left font-medium px-4 py-3">{{ __('monitoring.column.time') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($authentications as $authentication)
                    @php
                        $isLogin = str_contains(strtolower((string) $authentication->action_type), 'login')
                            && ! str_contains(strtolower((string) $authentication->action_type), 'logout');
                        $badge = $isLogin ? 'bg-active/15 text-active' : 'bg-foreground/10 text-muted';
                    @endphp
                    <tr class="border-b border-border/40 hover:bg-elevated/30 transition-colors">
                        <td class="px-4 py-3 text-foreground">
                            {{ $authentication->user->{config('user-monitoring.user.display_attribute')} ?? __('monitoring.guest') }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2.5 py-1 rounded-md text-x-small font-bold uppercase tracking-wide {{ $badge }}">
                                {{ $authentication->action_type }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-muted">{{ $authentication->ip }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-2 text-muted">
                                @include('LaravelUserMonitoring::layouts.platform', ['platform' => $authentication->platform])
                                {{ $authentication->platform }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2.5 py-1 rounded-md bg-brand/10 text-brand-bright text-x-small">
                                {{ $authentication->created_at->format('Y-m-d H:i') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <form method="post" action="{{ route('user-monitoring.authentications-monitoring-delete', $authentication->id) }}"
                                  onsubmit="return confirm(@js(__('monitoring.confirm_delete.authentications')))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="{{ __('monitoring.delete') }}"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-danger hover:bg-danger/15 transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-muted">{{ __('monitoring.empty.authentications') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $authentications->links() }}
    </div>
@endsection

@extends('LaravelUserMonitoring::layouts.master')

@section('title', 'Aksi')

@section('content')
    <div class="overflow-x-auto -mx-2 sm:mx-0">
        <table class="w-full whitespace-nowrap text-small font-mono">
            <thead>
                <tr class="text-x-small uppercase tracking-wider text-muted border-b border-border">
                    <th class="text-left font-medium px-4 py-3">Tabel</th>
                    <th class="text-left font-medium px-4 py-3">Pengguna</th>
                    <th class="text-left font-medium px-4 py-3">Aksi</th>
                    <th class="text-left font-medium px-4 py-3">IP</th>
                    <th class="text-left font-medium px-4 py-3">Platform</th>
                    <th class="text-left font-medium px-4 py-3">Waktu</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($actions as $action)
                    @php
                        // Map warna aksi ke token semantic (kelas tetap, tak dinamis, agar
                        // tak ke-purge Tailwind build). create=hijau, delete=merah, update=emas.
                        $badge = match ($action->getTypeColor()) {
                            'green'  => 'bg-active/15 text-active',
                            'red'    => 'bg-danger/15 text-danger',
                            'purple' => 'bg-gold/15 text-gold',
                            default  => 'bg-brand/15 text-brand-bright', // blue / read
                        };
                    @endphp
                    <tr class="border-b border-border/40 hover:bg-elevated/30 transition-colors">
                        <td class="px-4 py-3 text-foreground">{{ $action->table_name }}</td>
                        <td class="px-4 py-3 text-muted">
                            {{ $action->user->{config('user-monitoring.user.display_attribute')} ?? 'Tamu' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2.5 py-1 rounded-md text-x-small font-bold uppercase tracking-wide {{ $badge }}">
                                {{ $action->action_type }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-muted">{{ $action->ip }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center gap-2 text-muted">
                                @include('LaravelUserMonitoring::layouts.platform', ['platform' => $action->platform])
                                {{ $action->platform }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-block px-2.5 py-1 rounded-md bg-brand/10 text-brand-bright text-x-small">
                                {{ $action->created_at->format('Y-m-d H:i') }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <form method="post" action="{{ route('user-monitoring.actions-monitoring-delete', $action->id) }}"
                                  onsubmit="return confirm('Hapus catatan aksi ini?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="Hapus"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-lg text-danger hover:bg-danger/15 transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-muted">Belum ada data aksi.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $actions->links() }}
    </div>
@endsection

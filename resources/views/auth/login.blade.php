<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @include('auth._google-panel')
</x-guest-layout>

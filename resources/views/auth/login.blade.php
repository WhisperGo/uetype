{{-- Login page: shows any session status, then the Google sign-in panel. --}}
<x-guest-layout :back-url="$backUrl">
    <x-auth-session-status class="mb-4" :status="session('status')" />
    <x-session-error class="mb-4" :message="session('error')" />

    @include('auth._google-panel')
</x-guest-layout>

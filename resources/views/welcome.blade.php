<!DOCTYPE html>
<html lang="en">

@include('partials.header')

<body>
    @include('partials.navbar')

    <main class="py-1">
        @yield('content')
    </main>

    @include('partials.footer')
</body>
</html>
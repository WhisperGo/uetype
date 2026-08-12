{{-- The UeType application logo image.

     width/height are the file's INTRINSIC dimensions, not display size. Callers set the
     height in CSS (h-12 in the nav and the guest layout, h-10 on error pages) and leave
     the width `auto` -- so the browser only needs the ratio, and one pair of attributes
     serves all three. Without them it can't know that ratio until the (943 KB) PNG has
     downloaded: the image occupies zero width until then, and snaps to 48px on arrival,
     shoving the wordmark and the whole nav menu sideways on every page load. --}}
<img src="{{ asset('logo/logo.png') }}" alt="UeType" width="6250" height="6250"
    {{ $attributes->merge(['class' => 'block object-contain']) }} />

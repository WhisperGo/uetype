{{-- Official Chart.js logo: two-color donut chart — pink (#FF6384) & blue (#36A2EB),
     the two default Chart.js palette colors. The Simple Icons `chartdotjs` mark is
     monochrome only (single path, currentColor), so it can't be two-color. --}}
@props(['class' => 'w-4 h-4'])
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" {{ $attributes->merge(['class' => $class]) }}>
    {{-- Base ring (blue) --}}
    <path fill="#36A2EB"
        d="M16 3a13 13 0 1 0 0 26 13 13 0 0 0 0-26Zm0 4.5a8.5 8.5 0 1 1 0 17 8.5 8.5 0 0 1 0-17Z" />
    {{-- Active slice (pink), sweeping ~40% of the circumference from the top --}}
    <path fill="#FF6384"
        d="M16 3a13 13 0 0 1 11.26 19.5l-3.9-2.25A8.5 8.5 0 0 0 16 7.5V3Z" />
</svg>

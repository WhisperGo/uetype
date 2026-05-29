<div class="min-h-screen bg-[#323437] text-[#646669] font-mono selection:bg-yellow-500 selection:text-black outline-none flex py-12"
     x-data
     @keydown.window="if($event.key === 'Tab') { $event.preventDefault(); document.getElementById('restartButton').focus(); }">
    <div class="max-w-4xl w-full px-4 m-auto">
        
        <!-- Headers -->
        <div class="flex gap-4 mb-10 text-gray-500 text-lg tracking-widest justify-center">
            <span class="text-yellow-500">{{ $mode }}</span>
            <span>/</span>
            <span class="text-yellow-500">{{ $subMode }}</span>
        </div>

        <div class="flex flex-col md:flex-row justify-center gap-10 md:gap-24">
            <!-- Left Side (Main Stats) -->
            <div class="flex flex-col gap-6 items-end">
                <div class="text-right">
                    <div class="text-3xl text-gray-500 mb-1">wpm</div>
                    <div class="text-7xl text-yellow-500 font-bold leading-none">{{ $wpm }}</div>
                </div>
                <div class="text-right">
                    <div class="text-3xl text-gray-500 mb-1">acc</div>
                    <div class="text-7xl text-yellow-500 font-bold leading-none">{{ $accuracy }}%</div>
                </div>
            </div>

            <!-- Right Side (Detailed Stats) -->
            <div class="flex flex-col justify-center text-xl text-gray-400 gap-4 mt-8 md:mt-0">
                <div class="flex gap-4 md:justify-between w-48">
                    <span>test type</span>
                    <span class="text-yellow-500">{{ $mode }}</span>
                </div>
                <div class="flex gap-4 md:justify-between w-48">
                    <span>time</span>
                    <span class="text-yellow-500">{{ $time }}s</span>
                </div>
                <div class="flex gap-4 md:justify-between w-48">
                    <span>characters</span>
                    <span class="text-yellow-500" title="correct / total">{{ $correctKeystrokes }}/{{ $totalKeystrokes }}</span>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="mt-16 w-full h-64" wire:ignore>
            <canvas id="wpmChart"></canvas>
        </div>

        @script
        <script>
            const wpmData = @json($wpmHistory);
            const rawData = @json($rawHistory);
            const labelsData = Array.from({length: wpmData.length}, (_, i) => i + 1);

            const renderChart = () => {
                const canvas = document.getElementById('wpmChart');
                if (!canvas) return;
                
                const ctx = canvas.getContext('2d');
                if (window.myWpmChart) {
                    window.myWpmChart.destroy();
                }

                window.myWpmChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labelsData,
                        datasets: [
                            {
                                label: 'wpm',
                                data: wpmData,
                                borderColor: '#eab308',
                                backgroundColor: 'rgba(234, 179, 8, 0.1)',
                                borderWidth: 3,
                                tension: 0.4,
                                pointRadius: 2,
                                pointBackgroundColor: '#eab308',
                            },
                            {
                                label: 'raw',
                                data: rawData,
                                borderColor: '#646669',
                                borderWidth: 2,
                                tension: 0.4,
                                pointRadius: 0,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        scales: {
                            x: {
                                grid: { color: '#2c2e31' },
                                ticks: { color: '#646669' }
                            },
                            y: {
                                grid: { color: '#2c2e31' },
                                ticks: { color: '#646669' },
                                beginAtZero: true
                            }
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#2c2e31',
                                titleColor: '#d1d0c5',
                                bodyColor: '#eab308',
                                borderColor: '#eab308',
                                borderWidth: 1
                            }
                        }
                    }
                });
            };

            if (typeof Chart === 'undefined') {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
                script.onload = renderChart;
                document.head.appendChild(script);
            } else {
                renderChart();
            }
        </script>
        @endscript

        <!-- Keyboard Heatmap -->
        @php
            $keyboard = [
                ['q','w','e','r','t','y','u','i','o','p','[',']'],
                ['a','s','d','f','g','h','j','k','l',';',"'"],
                ['z','x','c','v','b','n','m',',','.','/']
            ];
            $maxMiss = count($missedChars) > 0 ? max($missedChars) : 0;
        @endphp
        
        <div class="mt-20 flex flex-col items-center gap-2">
            <h3 class="text-gray-500 text-xl tracking-widest mb-6">heatmap</h3>
            <div class="flex flex-col gap-2 md:gap-3">
                @foreach($keyboard as $rowIndex => $row)
                    <div class="flex justify-center gap-2 md:gap-3" style="margin-left: {{ $rowIndex * 1.5 }}rem;">
                        @foreach($row as $key)
                            @php
                                $missCount = $missedChars[$key] ?? 0;
                                $opacity = $maxMiss > 0 && $missCount > 0 ? 0.3 + (($missCount / $maxMiss) * 0.7) : 0;
                                $style = $opacity > 0 ? "background-color: rgba(202, 71, 84, {$opacity}); color: #d1d0c5;" : "background-color: #2c2e31; color: #646669;";
                            @endphp
                            <div class="w-10 h-10 md:w-12 md:h-12 rounded-lg flex items-center justify-center text-sm md:text-base font-bold shadow-sm transition-colors relative group"
                                 style="{{ $style }}">
                                {{ strtoupper($key) }}
                                
                                @if($missCount > 0)
                                <!-- Tooltip -->
                                <div class="absolute -top-10 bg-[#2c2e31] text-[#ca4754] px-2 py-1 rounded text-xs opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap z-10 shadow-lg border border-[#ca4754]/50">
                                    {{ $missCount }} missed
                                </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-24 flex justify-center">
            <a id="restartButton" href="/typing" wire:navigate class="text-gray-600 hover:text-[#d1d0c5] focus:text-yellow-500 focus:scale-110 transition-all transform hover:scale-110 outline-none p-2 rounded-xl group" title="Next Test">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 group-hover:stroke-yellow-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </div>
    </div>
</div>

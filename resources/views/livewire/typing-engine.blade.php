<div class="max-w-4xl mx-auto py-20 px-4" x-data="typingGame(@js($textToType))" @keydown.window="handleInput($event)">

    <!-- Statistik -->
    <div class="flex gap-8 mb-10 font-mono text-2xl">
        <div class="text-gray-400">wpm: <span class="text-yellow-500" x-text="wpm">0</span></div>
        <div class="text-gray-400">acc: <span class="text-yellow-500" x-text="accuracy">0</span>%</div>
        <div class="text-gray-400">time: <span class="text-yellow-500" x-text="timer">0</span>s</div>
    </div>

    <div
        class="relative text-3xl font-mono leading-relaxed tracking-tight select-none outline-none overflow-hidden h-40">
        <div class="flex flex-wrap content-start gap-x-4"> @php
            // Kita pecah teks menjadi kumpulan kata untuk rendering yang lebih baik
            $words = explode(' ', $textToType);
            $charPointer = 0;
        @endphp

            @foreach ($words as $word)
                <div class="flex">
                    @foreach (str_split($word) as $char)
                        <span class="relative"
                            :class="{
                                'text-gray-200': {{ $charPointer }} < currentIndex && inputResults[
                                    {{ $charPointer }}] === true,
                                'text-red-500 border-b-2 border-red-500': {{ $charPointer }} < currentIndex &&
                                    inputResults[{{ $charPointer }}] === false,
                                'text-gray-500': {{ $charPointer }} > currentIndex,
                                'border-l-2 border-yellow-500 animate-pulse': {{ $charPointer }} === currentIndex
                            }">{{ $char }}</span>
                        @php $charPointer++; @endphp
                    @endforeach

                    @if (!$loop->last)
                        <span class="w-2"
                            :class="{
                                'bg-red-500/30': {{ $charPointer }} < currentIndex && inputResults[
                                    {{ $charPointer }}] === false,
                                'border-l-2 border-yellow-500': {{ $charPointer }} === currentIndex
                            }">&nbsp;</span>
                        @php $charPointer++; @endphp
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    <!-- Tombol Reset -->
    <div class="mt-20 flex justify-center">
        <button @click="resetGame()" class="text-gray-500 hover:text-white transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
        </button>
    </div>
</div>

<script>
    function typingGame(text) {
        return {
            targetArray: text.split(''),
            currentIndex: 0,
            inputResults: [], // true jika benar, false jika salah
            startTime: null,
            endTime: null,
            wpm: 0,
            accuracy: 0,
            timer: 0,
            timerInterval: null,
            isFinished: false,

            handleInput(e) {
                if (this.isFinished) return;

                // 1. Abaikan tombol khusus (Shift, Ctrl, Alt, dll)
                if (e.key.length > 1 && e.key !== 'Backspace') return;

                // 2. Mulai Timer pada pencetan pertama
                if (!this.startTime) {
                    this.startTime = Date.now();
                    this.timerInterval = setInterval(() => {
                        this.timer = Math.floor((Date.now() - this.startTime) / 1000);
                        this.calculateStats();
                    }, 1000);
                }

                // 3. Logika Backspace
                if (e.key === 'Backspace') {
                    if (this.currentIndex > 0) {
                        this.currentIndex--;
                        this.inputResults.pop();
                        this.calculateStats();
                    }
                    return;
                }

                // 4. Bandingkan Input
                if (e.key === this.targetArray[this.currentIndex]) {
                    this.inputResults[this.currentIndex] = true;
                } else {
                    this.inputResults[this.currentIndex] = false;
                }

                this.currentIndex++;

                // 5. Cek jika Selesai
                if (this.currentIndex === this.targetArray.length) {
                    this.finishGame();
                }

                this.calculateStats();
            },

            calculateStats() {
                if (!this.startTime) return;

                const timeElapsed = (Date.now() - this.startTime) / 60000; // menit
                const correctChars = this.inputResults.filter(r => r === true).length;

                // Rumus: (karakter_benar / 5) / menit
                this.wpm = Math.round((correctChars / 5) / timeElapsed) || 0;
                this.accuracy = Math.round((correctChars / this.currentIndex) * 100) || 0;
            },

            finishGame() {
                this.isFinished = true;
                clearInterval(this.timerInterval);

                // Panggil Livewire untuk simpan ke database
                @this.saveResult(this.wpm, this.accuracy);
            },

            resetGame() {
                location.reload(); // Cara paling simple untuk reset state
            }
        }
    }
</script>

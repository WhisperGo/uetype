<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Halaman About dibuat sebagai Livewire full-page component BUKAN karena
 * butuh interaktivitas/reactive state (isinya statis, tidak ada wire:model,
 * tidak ada method action) — tapi karena layouts/app.blade.php di project
 * ini pakai {{ $slot }} ala Livewire (@livewireStyles/@livewireScripts,
 * tanpa @yield). $slot itu hanya terisi kalau component di-render lewat
 * atribut #[Layout(...)] di bawah ini; Blade view biasa via @extends tidak
 * bisa mengisinya.
 *
 * Data tim & tech stack tetap hardcode di sini (bukan query database),
 * persis seperti rencana awal — cuma "wadah"-nya yang berubah jadi Livewire
 * component supaya cocok dengan layout yang sudah ada.
 */
#[Layout('layouts.app')]
class About extends Component
{
    public function render()
    {
        $team = [
            ['name' => 'Jessie La Vonna Sanjaya', 'role' => 'Product & Design', 'photo' => '/images/team/jessie.png'],
            ['name' => 'Jason Wijaya', 'role' => 'Frontend Development', 'photo' => '/images/team/jason.png'],
            ['name' => 'William Fernando Sukemi', 'role' => 'Backend Development', 'photo' => '/images/team/nando.png'],
            ['name' => 'Imanuel Yusuf Setio Budi', 'role' => 'Backend Development', 'photo' => '/images/team/yusuf.png'],
            ['name' => 'Kevin Fernando', 'role' => 'QA & Testing', 'photo' => '/images/team/kevin.png'],
        ];

        $stack = ['Laravel', 'MySQL', 'Tailwind CSS', 'Alpine.js', 'Vite'];

        return view('livewire.about', compact('team', 'stack'));
    }
}
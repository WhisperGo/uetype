<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Halaman About statis (tanpa reactive state), dibuat sebagai Livewire component
 * karena layouts/app.blade.php memakai {{ $slot }} yang hanya terisi lewat #[Layout(...)].
 * Data tim & tech stack hardcode di sini, bukan dari database.
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

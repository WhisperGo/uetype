<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/** Static About page (team & tech stack hardcoded); a full-page Livewire component. */
#[Layout('layouts.app')]
class About extends Component
{
    public function render()
    {
        // 'key' menautkan ke bio di lang/*/about.php (about.bio.<key>) supaya
        // teksnya bisa diterjemahkan; nama & foto tetap di sini.
        $team = [
            ['key' => 'jessie', 'name' => 'Jessie La Vonna Sanjaya', 'role' => 'Product & Design', 'photo' => '/images/team/jessie.png'],
            ['key' => 'jason', 'name' => 'Jason Wijaya', 'role' => 'Frontend Development', 'photo' => '/images/team/jason.png'],
            ['key' => 'nando', 'name' => 'William Fernando Sukemi', 'role' => 'Backend Development', 'photo' => '/images/team/nando.png'],
            ['key' => 'yusuf', 'name' => 'Imanuel Yusuf Setio Budi', 'role' => 'Backend Development', 'photo' => '/images/team/yusuf.png'],
            ['key' => 'kevin', 'name' => 'Kevin Fernando', 'role' => 'QA & Testing', 'photo' => '/images/team/kevin.png'],
        ];

        // Tiap item: label + komponen Blade Icon (Simple Icons, prefix `simpleicon`).
        // `color` = warna brand resmi untuk ikon; ikon SVG mewarisi via currentColor.
        $stack = [
            ['name' => 'Laravel', 'icon' => 'simpleicon-laravel', 'color' => '#FF2D20'],
            ['name' => 'Livewire', 'icon' => 'simpleicon-livewire', 'color' => '#FB70A9'],
            ['name' => 'Alpine.js', 'icon' => 'simpleicon-alpinedotjs', 'color' => '#8BC0D0'],
            ['name' => 'Tailwind CSS', 'icon' => 'simpleicon-tailwindcss', 'color' => '#06B6D4'],
            ['name' => 'Vite', 'icon' => 'simpleicon-vite', 'color' => '#646CFF'],
            ['name' => 'PHP', 'icon' => 'simpleicon-php', 'color' => '#777BB4'],
            ['name' => 'MySQL', 'icon' => 'simpleicon-mysql', 'color' => '#4479A1'],
        ];

        return view('livewire.about', compact('team', 'stack'));
    }
}

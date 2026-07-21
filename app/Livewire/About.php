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
        // 'key' links to the bio in lang/*/about.php (about.bio.<key>) so the text can be
        // translated; name & photo stay here.
        $team = [
            ['key' => 'jessie', 'name' => 'Jessie La Vonna Sanjaya', 'role' => 'Product & Design', 'photo' => '/images/team/jessie.png'],
            ['key' => 'jason', 'name' => 'Jason Wijaya', 'role' => 'Frontend Development', 'photo' => '/images/team/jason.png'],
            ['key' => 'nando', 'name' => 'William Fernando Sukemi', 'role' => 'Backend Development', 'photo' => '/images/team/nando.png'],
            ['key' => 'yusuf', 'name' => 'Imanuel Yusuf Setio Budi', 'role' => 'Backend Development', 'photo' => '/images/team/yusuf.png'],
            ['key' => 'kevin', 'name' => 'Kevin Fernando', 'role' => 'QA & Testing', 'photo' => '/images/team/kevin.png'],
        ];

        // Each item: label + Blade Icon component (Simple Icons, prefix `simpleicon`).
        // `color` = the icon's official brand color; the SVG icon inherits via currentColor.
        $stack = [
            ['name' => 'Laravel', 'icon' => 'simpleicon-laravel', 'color' => '#FF2D20'],
            ['name' => 'Livewire', 'icon' => 'simpleicon-livewire', 'color' => '#FB70A9'],
            ['name' => 'Alpine.js', 'icon' => 'simpleicon-alpinedotjs', 'color' => '#8BC0D0'],
            ['name' => 'Tailwind CSS', 'icon' => 'simpleicon-tailwindcss', 'color' => '#06B6D4'],
            ['name' => 'Vite', 'icon' => 'simpleicon-vite', 'color' => '#646CFF'],
            ['name' => 'PHP', 'icon' => 'simpleicon-php', 'color' => '#777BB4'],
            ['name' => 'MySQL', 'icon' => 'simpleicon-mysql', 'color' => '#4479A1'],
            // Reverb has no icon of its own in Simple Icons -- use the Laravel logo (part of its ecosystem).
            ['name' => 'Laravel Reverb', 'icon' => 'simpleicon-laravel', 'color' => '#FF2D20'],
            // color=null => the two-color Chart.js logo (pink + blue), not monochrome.
            ['name' => 'Chart.js', 'icon' => 'icon-chartjs-color', 'color' => null],
            // Social login uses Socialite with the Google OAuth provider.
            // color=null => use the real 4-color Google logo (icon-google-color component),
            // not the monochrome Simple Icons glyph that can only be one color.
            ['name' => 'Google OAuth', 'icon' => 'icon-google-color', 'color' => null],
        ];

        return view('livewire.about', compact('team', 'stack'));
    }
}

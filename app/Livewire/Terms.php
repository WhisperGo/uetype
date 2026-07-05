<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Sama seperti About & Privacy: statis, tidak ada reactive state. Dibuat
 * sebagai Livewire full-page component supaya {{ $slot }} di
 * layouts/app.blade.php terisi lewat #[Layout(...)] di bawah.
 */
#[Layout('layouts.app')]
class Terms extends Component
{
    public function render()
    {
        $sections = trans('terms.sections');

        return view('livewire.terms', compact('sections'));
    }
}
<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

/** Static Terms page; a full-page Livewire component (like About/Privacy). */
#[Layout('layouts.app')]
class Terms extends Component
{
    public function render()
    {
        $sections = trans('terms.sections');

        return view('livewire.terms', compact('sections'));
    }
}
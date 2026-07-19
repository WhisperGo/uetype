<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsChatAccess;
use App\Livewire\Concerns\ManagesChatConversation;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The chat page for direct messages and clan channels: active conversation,
 * paginated history, send/edit/delete, driven by the Message* broadcast events.
 *
 * Seluruh alur percakapannya ada di ManagesChatConversation, dipakai bersama
 * ChatOverlay. Yang tersisa di sini hanya yang memang khas halaman penuh:
 * state tersimpan di URL, inbox tanpa batas, dan layout.
 */
class Chat extends Component
{
    use GuardsChatAccess, ManagesChatConversation;

    public const PAGE_SIZE = 30;

    // Mode percakapan aktif: 'dm' | 'clan'. Null = tampilan inbox saja.
    // #[Url] hanya di sini: halaman penuh bisa di-bookmark & dibagikan,
    // sedangkan overlay tak boleh mengubah URL halaman yang sedang dibuka.
    #[Url(as: 'mode')]
    public ?string $activeMode = null;

    // Username teman yang percakapannya sedang dibuka (mode 'dm').
    #[Url(as: 'with')]
    public ?string $withUsername = null;

    protected function pageSize(): int
    {
        return self::PAGE_SIZE;
    }

    /** Kembali ke inbox. */
    public function closeConversation(): void
    {
        $this->resetConversation();
    }

    public function render()
    {
        return view('livewire.chat')->layout('layouts.app');
    }
}

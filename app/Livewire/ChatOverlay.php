<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsChatAccess;
use App\Livewire\Concerns\ManagesChatConversation;
use Livewire\Component;

/**
 * Chat overlay/drawer: mounted once, globally, in layouts.app (outside the
 * Livewire slot, alongside the toast stack) so it survives wire:navigate
 * and is reachable from any page. Same feature set as the full Chat page
 * (reply/edit/delete/clear-chat), but state is plain (no #[Url]) so opening
 * it never rewrites the host page's query string — that's the full /chat
 * page's job, and stays reachable via the "Buka penuh" link.
 *
 * Alur percakapannya dipakai bersama Chat lewat ManagesChatConversation.
 */
class ChatOverlay extends Component
{
    use GuardsChatAccess, ManagesChatConversation;

    public const OVERLAY_PAGE_SIZE = 15;

    /** Daftar kontak dibatasi: drawer sempit, bukan inbox penuh. */
    public const CONTACT_LIMIT = 8;

    public bool $open = false;

    // Mode percakapan aktif: 'dm' | 'clan'. Null = tampilan picker kontak.
    // Tanpa #[Url] -- lihat class doc-comment.
    public ?string $activeMode = null;

    public ?string $withUsername = null;

    protected function pageSize(): int
    {
        return self::OVERLAY_PAGE_SIZE;
    }

    protected function contactLimit(): ?int
    {
        return self::CONTACT_LIMIT;
    }

    // ---- AKSI: BUKA/TUTUP ----

    public function toggleOverlay(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Kembali ke daftar kontak TANPA menutup drawer. Beda semantik dari
     * closeConversation() di halaman penuh, karena itu namanya sendiri.
     */
    public function backToPicker(): void
    {
        $this->resetConversation();
    }

    /**
     * Alias untuk view overlay. Nama berbeda dari halaman penuh karena isinya
     * memang dibatasi CONTACT_LIMIT, bukan inbox lengkap.
     */
    public function getRecentContactsProperty()
    {
        return $this->conversations;
    }

    public function getUnreadCountProperty(): int
    {
        return $this->totalUnread;
    }

    public function render()
    {
        return view('livewire.chat-overlay');
    }
}

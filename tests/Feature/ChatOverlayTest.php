<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\FriendshipStatus;
use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Livewire\ChatOverlay;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function overlayAcceptedFriends(): array
{
    $me = User::factory()->create();
    $friend = User::factory()->create();

    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    return [$me, $friend];
}

function overlayClanWithMembers(int $memberCount = 2): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Overlay Clan '.uniqid('', true), 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $members = [$leader];
    for ($i = 1; $i < $memberCount; $i++) {
        $member = User::factory()->create();
        ClanMember::create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);
        $members[] = $member;
    }

    return [$clan, $members];
}

it('toggles open state', function () {
    $me = User::factory()->create();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->assertSet('open', false)
        ->call('toggleOverlay')
        ->assertSet('open', true)
        ->call('toggleOverlay')
        ->assertSet('open', false);
});

it('lets an accepted friend open a DM in the overlay and send a message', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->assertSet('activeMode', 'dm')
        ->assertSet('withUsername', $friend->username)
        ->set('body', 'Halo dari overlay!')
        ->call('sendMessage');

    assertMessageStored([
        'sender_id' => $me->id,
        'recipient_id' => $friend->id,
        'clan_id' => null,
    ], 'Halo dari overlay!');
});

it('does not let the overlay DM a non-friend (trust boundary)', function () {
    $me = User::factory()->create();
    $stranger = User::factory()->create();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $stranger->username)
        ->assertSet('activeMode', null);

    $this->assertDatabaseMissing('messages', ['sender_id' => $me->id, 'recipient_id' => $stranger->id]);
});

it('broadcasts DirectMessageSent when sent via the overlay', function () {
    Event::fake([DirectMessageSent::class]);
    [$me, $friend] = overlayAcceptedFriends();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->set('body', 'Halo!')
        ->call('sendMessage');

    Event::assertDispatched(DirectMessageSent::class, fn ($e) => $e->message->recipient_id === $friend->id);
});

it('lets an active clan member open clan chat in the overlay and send a message', function () {
    [$clan, $members] = overlayClanWithMembers();
    $leader = $members[0];

    Event::fake([ClanMessageSent::class]);

    Livewire::actingAs($leader)->test(ChatOverlay::class)
        ->call('openClanChat')
        ->assertSet('activeMode', 'clan')
        ->set('body', 'Halo clan!')
        ->call('sendMessage');

    assertMessageStored([
        'sender_id' => $leader->id,
        'clan_id' => $clan->id,
    ], 'Halo clan!');
    Event::assertDispatched(ClanMessageSent::class);
});

it('returns to the picker without closing the drawer', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('toggleOverlay')
        ->call('openDm', $friend->username)
        ->assertSet('activeMode', 'dm')
        ->call('backToPicker')
        ->assertSet('activeMode', null)
        ->assertSet('open', true);
});

it('limits recentContacts to the capped count', function () {
    $me = User::factory()->create();

    for ($i = 0; $i < 12; $i++) {
        $friend = User::factory()->create();
        Friendship::create([
            'requester_id' => $me->id,
            'addressee_id' => $friend->id,
            'status' => FriendshipStatus::Accepted,
        ]);
    }

    $component = Livewire::actingAs($me)->test(ChatOverlay::class);

    expect($component->get('recentContacts'))->toHaveCount(8);
});

it('computes unread count matching the same query as the full chat page', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'satu']);
    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'dua']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->assertSet('unreadCount', 2);
});

it('marks incoming DMs as read when opening the conversation in the overlay', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'halo']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->assertSet('unreadCount', 0);
});

it('lets the sender edit their own message from the overlay', function () {
    [$me, $friend] = overlayAcceptedFriends();
    $message = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'salah ketik']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->call('startEdit', $message->id)
        ->set('editBody', 'sudah benar')
        ->call('saveEdit');

    expect($message->fresh()->body)->toBe('sudah benar');
    expect($message->fresh()->isEdited())->toBeTrue();
});

it('lets the sender delete a message for everyone from the overlay', function () {
    [$me, $friend] = overlayAcceptedFriends();
    $message = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'oops']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->call('deleteForEveryone', $message->id);

    expect($message->fresh()->isDeletedForEveryone())->toBeTrue();
});

it('clears DM history from the overlay for the clearing user only', function () {
    [$me, $friend] = overlayAcceptedFriends();
    Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'halo']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->call('confirmClear');

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->assertSet('messages', function ($messages) {
            return $messages->isEmpty();
        });
});

/**
 * Regression: the sender spamming WHILE their DM is open used to keep lighting the FAB's
 * unread dot, because the new messages landed as unread even though the user was looking
 * right at them. Opening the thread marks read once; refreshChat() (fired on each incoming
 * message) must keep the OPEN thread read.
 */
it('does not show the unread badge for the DM the user is currently viewing', function () {
    [$me, $friend] = overlayAcceptedFriends();

    $overlay = Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username);

    // The friend spams three more messages while the thread is open.
    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'spam 1']);
    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'spam 2']);
    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'spam 3']);

    // The client relays each arrival as message-received while the overlay is active.
    $overlay->call('refreshChat');

    expect($overlay->get('unreadCount'))->toBe(0);
    expect(Message::where('recipient_id', $me->id)->whereNull('read_at')->count())->toBe(0);
});

/**
 * ===== TOMBOL FAB: AKSES KEYBOARD =====
 *
 * Tombol chat dulu HANYA punya @pointerdown (untuk membedakan geser dari ketuk saat
 * FAB masih bisa di-drag). Akibatnya Enter/Space -- yang oleh browser dikirim sebagai
 * event `click` -- tak didengarkan siapa pun, jadi pengguna keyboard-only tak bisa
 * membuka chat overlay di halaman mana pun.
 *
 * Drag sudah dibuang, jadi tak ada lagi alasan menghindari `click`. Test ini mengunci
 * keduanya sekaligus: klik harus ada, pointerdown tak boleh kembali.
 */
it('membuka chat lewat click sehingga tombolnya bisa dipakai dengan keyboard', function () {
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat-overlay.blade.php')));

    expect($markup)->toContain('@click="open = ! open"')
        ->and($markup)->not->toContain('@pointerdown');
});

it('tidak lagi menawarkan FAB yang bisa digeser', function () {
    // Komentar dibuang: keduanya sengaja MENJELASKAN kenapa drag dibuang, jadi teks
    // mentahnya memuat istilah yang justru sedang dilarang.
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat-overlay.blade.php')));
    $js = file_get_contents(resource_path('js/chat-dock.js'));

    // Affordance drag di markup.
    expect($markup)->not->toContain('cursor-grab')
        ->and($markup)->not->toContain('bubbleStyle()')
        ->and($markup)->not->toContain('panelStyle()');

    // Dan logikanya di modul: cek pemanggilan, bukan sekadar penyebutan di prosa.
    expect($js)->not->toContain('startDrag(')
        ->and($js)->not->toContain('window.__chatOverlayAnchor');
});

/**
 * ===== MICRO-INTERACTION FAB =====
 *
 * Pengganti drag: "sensasi" dipindah dari POSISI tombol (properti yang justru harus
 * stabil, dan yang dulu memakan akses keyboard) ke REAKSI tombol.
 */

/**
 * Badge unread FAB, REALTIME. Dulu badge murni server-rendered (`wire:key="fab-unread-N"`):
 * ia hanya menyala saat halaman di-load/navigasi, TIDAK saat DM masuk sementara drawer
 * tertutup -- karena chat-runtime sengaja menahan round-trip Livewire saat drawer tak aktif,
 * dan round-trip itulah yang menghitung ulang badge.
 *
 * Sekarang `unreadCount` di-entangle (server tetap sumber kebenaran, dihitung ulang dari DB
 * tiap render) DAN di-bump di sisi klien saat drawer tertutup lewat event `chat-unread-bump`
 * yang dipancarkan toasts.js dengan kondisi yang sama seperti toast (DM yang tak sedang
 * dibuka). Badge menyala tanpa round-trip, lalu selaras lagi ke angka server saat render
 * berikutnya (drawer dibuka / DM dibuka -> ditandai terbaca).
 */
it('menyalakan badge unread FAB realtime tanpa round-trip saat drawer tertutup', function () {
    $overlay = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat-overlay.blade.php')));
    $dock = file_get_contents(resource_path('js/chat-dock.js'));
    $toasts = file_get_contents(resource_path('js/toasts.js'));

    // Root menyeed & mereconcile dari server via entangle, dan menggerakkan badge lewat
    // state Alpine `unread` -- bukan lagi @if server + wire:key.
    expect($overlay)->toContain("@entangle('unreadCount')")
        ->and($overlay)->toContain('x-show="unread > 0"')
        ->and($overlay)->not->toContain('fab-unread-');

    // Bump HANYA saat drawer tertutup: kalau terbuka, overlay aktif dan server yang sync.
    expect($overlay)->toMatch('/@chat-unread-bump\.window="[^"]*!\s*open[^"]*unread\+\+/');

    // Dock mengekspos state unread (di-entangle dari properti server).
    expect($dock)->toContain('unread');

    // toasts.js memancarkan event bump (dengan kondisi DM-tak-dibuka yang sama seperti toast).
    expect($toasts)->toContain('chat-unread-bump');
});

/** Animasi pop tetap ada, kini dipicu perubahan state `unread`, bukan penggantian node. */
it('memutar ulang animasi badge saat unread bertambah', function () {
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat-overlay.blade.php')));

    expect($markup)->toContain('chat-badge-pop')
        ->and($markup)->toContain('x-effect');

    expect(file_get_contents(resource_path('css/app.css')))
        ->toContain('@keyframes chat-badge-pop');
});

it('memberi umpan balik hover dan tekan pada FAB', function () {
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat-overlay.blade.php')));

    // Dipersempit ke class TOMBOL-nya saja: `transition-colors` sah dipakai elemen lain di
    // dalam drawer, jadi mencari di seluruh berkas akan salah tuduh.
    preg_match('/class="(fixed z-\[56\][^"]*)"/', $markup, $fab);
    expect($fab)->not->toBeEmpty('Class FAB tak ditemukan -- selektornya berubah?');

    // Angkat saat hover, mengecil saat ditekan -- yang terakhir menggantikan isyarat
    // taktil yang hilang bersama `active:cursor-grabbing`.
    expect($fab[1])->toContain('hover:-translate-y-0.5')
        ->and($fab[1])->toContain('active:scale-95')
        // `transition-colors` saja tak akan meng-ease transform & shadow-nya.
        ->and($fab[1])->not->toContain('transition-colors');
});

/**
 * Animasi baru tak boleh memaksa pengguna dengan sensitivitas vestibular. Tak ada guard
 * per-animasi: blok global di app.css meratakan semuanya sekaligus -- test ini memastikan
 * blok itu tetap ada saat animasi bertambah.
 */
it('tetap menghormati preferensi kurangi gerakan setelah animasi baru ditambahkan', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('prefers-reduced-motion')
        ->and($css)->toContain('animation-duration: 0.01ms !important');
});

/**
 * ===== POSISI FAB: SUDUT, BUKAN DIANGKAT =====
 *
 * FAB dulu dinaikkan (bottom-16) untuk mencoba melewati footer. Itu keliru dua arah
 * sekaligus: di desktop terlihat mengambang lepas dari sudut, sementara di mobile footer
 * yang lebih tinggi & rata-tengah TETAP tertutup. Satu offset tetap tak akan pernah
 * memuaskan keduanya.
 *
 * Perbaikannya memisah dua urusan itu: FAB kembali ke sudut sejati (bottom-5, simetris
 * dengan right-5), dan footer-lah yang menyediakan safe-zone-nya sendiri (padding bawah di
 * mobile, jarak horizontal di desktop). Test ini mengunci KEDUANYA supaya kompromi lama
 * tak menyelinap balik -- kalau salah satu hilang, footer tertutup lagi.
 */
it('menambatkan FAB chat ke sudut dan memberi footer jarak aman, bukan menaikkan tombolnya', function () {
    $overlay = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat-overlay.blade.php')));
    $layout = tanpaKomentarBlade(file_get_contents(resource_path('views/layouts/app.blade.php')));

    // FAB di sudut, simetris dengan right-5, tidak dinaikkan ke tengah layar.
    expect($overlay)->toContain('fixed z-[56] bottom-5 right-5')
        ->and($overlay)->not->toContain('bottom-16');

    // Footer menyediakan safe-zone-nya sendiri: ruang vertikal di mobile (di-reset di sm+
    // begitu FAB berada di sampingnya) dan jarak horizontal di desktop.
    expect($layout)->toContain('pb-24 sm:pb-6')
        ->and($layout)->toContain('sm:me-20');
});

/** Drawer harus diumumkan sebagai milik tombolnya, bukan panel lepas. */
it('menghubungkan tombol FAB dengan drawer lewat aria', function () {
    $markup = file_get_contents(resource_path('views/livewire/chat-overlay.blade.php'));

    expect($markup)->toContain('aria-controls="chat-overlay-panel"')
        ->and($markup)->toContain('id="chat-overlay-panel"')
        ->and($markup)->toContain(':aria-expanded');
});

/** A message from a DIFFERENT friend, not the open one, must still count as unread. */
it('still counts unread messages from a conversation that is not open', function () {
    [$me, $friend] = overlayAcceptedFriends();
    $other = User::factory()->create();
    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $other->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    $overlay = Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username);

    // Someone else messages while the user is viewing $friend's thread.
    Message::create(['sender_id' => $other->id, 'recipient_id' => $me->id, 'body' => 'hey']);

    $overlay->call('refreshChat');

    expect($overlay->get('unreadCount'))->toBe(1);
});

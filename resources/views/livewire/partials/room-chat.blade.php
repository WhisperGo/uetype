{{--
    Multiplayer lobby room chat panel. Broadcast-only: messages are NOT stored in the
    database — they are only broadcast over the 'room.{code}' channel (RoomMessageSent
    event) and held in client-side Alpine state. So a newly joined player sees no
    prior history; that is the intended behavior for this ephemeral chat.

    Used in two places (the 'waiting' block and the result modal), hence a partial:
    - $currentUserId : the active user's id, to tell own bubbles from others'.

    Flow:
    - Send    -> optimistic local append + $wire.sendRoomMessage(body) [broadcast ->toOthers]
    - Receive -> window 'room-message-received' (relayed by race-echo.js) -> append.
--}}
<div
    x-data="roomChat({
        me: {{ $currentUserId }},
        youLabel: @js(__('multiplayer.chat_you')),
        joinLabel: @js(__('multiplayer.chat_joined', ['name' => ':name'])),
        leaveLabel: @js(__('multiplayer.chat_left', ['name' => ':name'])),
    })"
    class="border bg-surface/40 border-border/40 rounded-2xl overflow-hidden flex flex-col"
    style="max-height: 22rem;">

    <div class="px-4 py-3 border-b border-border/40 flex items-center gap-2 shrink-0">
        <span class="text-sm leading-none">&#128172;</span>
        <h3 class="text-xs uppercase tracking-widest text-muted font-mono font-bold">
            {{ __('multiplayer.chat_title') }}
        </h3>
    </div>

    {{-- Message list. x-ref="log" is used to auto-scroll to the bottom on new messages. --}}
    <div x-ref="log" class="flex-1 overflow-y-auto chat-scroll px-4 py-3 space-y-2.5 min-h-[8rem]">
        <template x-if="messages.length === 0">
            <p class="text-xs font-mono text-muted/70 text-center py-6">
                {{ __('multiplayer.chat_empty') }}
            </p>
        </template>

        {{-- Bubbles and colors mirror the chat page (components/chat/message):
             own messages = gold (rounded-br-md), others' = translucent white
             (rounded-bl-md) with the sender's name above it.
             System messages (join/leave) are centered and faint (not bubbles). --}}
        <template x-for="(msg, i) in messages" :key="i">
            <div>
                {{-- Presence notice: a faint centered pill, deliberately unobtrusive. --}}
                <template x-if="msg.system">
                    <div class="flex justify-center">
                        <span class="px-3 py-1 rounded-full bg-white/[0.03] font-mono text-[0.7rem] text-muted/60"
                            x-text="msg.body"></span>
                    </div>
                </template>

                {{-- Regular message. --}}
                <template x-if="!msg.system">
                    <div class="flex" :class="msg.mine ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[75%]" :class="msg.mine ? '' : 'flex flex-col items-start'">
                            <p x-show="!msg.mine" class="font-mono text-[0.65rem] mb-1 text-muted px-1" x-text="msg.username"></p>
                            <div class="px-4 py-2.5 rounded-2xl text-sm font-mono break-words"
                                :class="msg.mine ? 'bg-gold text-background rounded-br-md' : 'bg-white/5 text-foreground rounded-bl-md'"
                                x-text="msg.body"></div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Send form. Input and button mirror the chat page (components/chat/composer):
         input bg-surface/40 border-white/10 focus:border-gold, gold send button (btn-gold). --}}
    <form x-on:submit.prevent="send()" class="flex items-center gap-3 p-4 border-t border-white/5 shrink-0">
        <input type="text" x-model="draft" maxlength="500" autocomplete="off"
            placeholder="{{ __('multiplayer.chat_placeholder') }}"
            class="flex-1 px-4 py-2.5 rounded-2xl text-sm bg-surface/40 border border-white/10 font-mono text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
        <x-btn-gold type="submit" size="xl" class="disabled:opacity-40" x-bind:disabled="draft.trim() === ''">
            {{ __('multiplayer.chat_send') }}
        </x-btn-gold>
    </form>
</div>

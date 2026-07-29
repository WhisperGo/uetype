<?php

return [
    'title' => 'CLAN',
    'war_title' => 'CLAN WAR',
    'leaderboard_title' => 'CLAN LEADERBOARD',

    'leaderboard' => 'Leaderboard',
    'back_to_clan' => '← Back to Clan',
    'back_to_clan_plain' => 'Back to Clan',
    'clan_war' => 'Clan War',
    'chat' => 'Clan Chat',

    'level' => 'Lv :level',
    'power' => 'power',
    'user_level' => 'level :level',
    'members' => ':count / :max members',
    'members_count' => ':count members',
    'members_heading' => 'Members (:count)',
    'power_inline' => 'power :value',

    'tab' => [
        // No 'my_clan' key: that tab has no button. Once you are in a clan the page IS your
        // clan, so there is nothing to switch between and the whole bar is not rendered.
        //
        // The labels carry no "Clan" either: the page title, the nav item and the browser
        // title all say it already, and repeating it made both chips long enough to collide.
        'browse' => 'Browse',
        'create' => 'Create',
        'aria' => 'Clan tabs',
    ],

    'my_clan' => [
        'leave' => 'Leave Clan',
        'join_requests' => 'Join Requests (:count)',
        'accept' => 'Accept',
        'reject' => 'Reject',
        'kick' => 'Kick',
        'manage' => 'Manage Clan',
        'edit' => 'Edit Clan Identity',
        'edit_heading' => 'Edit Clan Identity',
        'edit_save' => 'Save Changes',
        'edit_cancel' => 'Cancel',
        'promote' => 'Make Co-Leader',
        'demote' => 'Demote to Member',
        'transfer' => 'Transfer Leadership',
        'disband' => 'Disband Clan',
    ],

    'browse' => [
        'search_placeholder' => 'Search by clan name…',
        'joined' => 'Joined',
        'request_sent' => 'Request Sent',
        'cancel_request' => 'Cancel',
        'join' => '+ Join',
    ],

    'create' => [
        'preview_name' => 'Clan name',
        'preview_desc' => 'Your clan preview',
        'name_label' => 'Clan Name',
        'name_placeholder' => 'e.g. Speed Demons',
        'tag_label' => 'Tag (optional)',
        'tag_placeholder' => 'e.g. SPD',
        'desc_label' => 'Description (optional)',
        'desc_placeholder' => 'What is your clan about?',
        'emblem_label' => 'Emblem',
        'color_label' => 'Accent Color',
        'submit' => 'Create Clan',
    ],

    'empty' => [
        'no_clan_title' => "You're not in a clan yet",
        'no_clan_body' => 'Browse existing clans or create your own',
        'no_results_title' => 'No clans found',
        'no_results_body' => 'Nothing matches ":query"',
        'no_results_alt' => 'Be the first to create one',
        'leaderboard_title' => 'No clans yet',
        'leaderboard_body' => 'Create a clan to appear on the leaderboard',
        'history' => 'No finished wars yet.',
    ],

    'war' => [
        'go_to_clans' => 'Go to Clans',
        'no_clan_title' => 'Join a clan first',
        'no_clan_body' => 'Clan War is only available once you have joined a clan',
        'clan_power' => ':name · Power :power',
        'incoming' => 'Incoming Challenge',
        'incoming_suffix' => '(power :power) is challenging your clan.',
        'respond_before' => 'Must respond before :time',
        'accept' => 'Accept',
        'decline' => 'Decline',
        'waiting' => 'Waiting for response',
        'waiting_prefix' => 'Waiting for',
        'waiting_suffix' => '(power :power) to respond to your challenge.',
        'expires_at' => 'Expires automatically on :time if unanswered',
        'ongoing' => 'War Ongoing',
        'vs_prefix' => 'vs',
        'vs_suffix' => '(power :power)',
        'ends_at' => 'Ends :time',
        'your_points' => 'Your Points',
        'opponent_points' => 'Opponent',
        'modes_heading' => 'War Modes',
        'modes_hint' => 'Claim an open mode and play it. Each mode can only be done once by your clan.',
        'mode_label' => ':mode · :config',
        'ceiling' => 'max :points pts',
        'claim_play' => 'Claim & Play',
        'claimed_by' => 'claimed by :name',
        'play' => 'Play',
        'cancel_claim' => 'Cancel Claim',
        'done_by' => ':name',
        'stat_survival' => 'lasted :value s',
        'stat_wpm' => 'wpm :value',
        'stat_accuracy' => 'accuracy :value%',
        'challenge_heading' => 'Challenge a Clan',
        'challenge' => 'Challenge',
        'challengeable_empty' => 'No clans available to challenge right now.',
        'not_in_war' => 'Your clan is not in a war right now. Ask your leader to challenge another clan.',
        'history_heading' => 'War History',
        'history_vs' => 'vs :name',
        'match_history' => 'Match History',
        'vs_label' => 'vs',
    ],

    'mode' => [
        'words' => 'Words',
        'time' => 'Time',
        'survival' => 'Survival',
        'config_time' => ':n s',
        'config_words' => ':n words',
        'survival_hard' => 'Hard',
    ],

    'result' => [
        'win' => 'win',
        'draw' => 'draw',
        'loss' => 'loss',
    ],

    'leaderboard_row' => [
        'stats' => 'Lv :level · :members members · :wins wins',
        'your_clan' => 'your clan',
        'your_clan_dot' => '· your clan',
    ],

    'modal' => [
        'cancel' => 'Cancel',
        'leave_title' => 'Leave :name?',
        'leave_body' => 'You can request to join again anytime, but your membership progress will be lost.',
        'leave_confirm' => 'Leave Clan',
        'kick_title' => 'Remove this member?',
        'kick_body' => 'This member will be removed from the clan and must request to join again to return.',
        'kick_confirm' => 'Remove',
        'cancel_claim_title' => 'Cancel this claim?',
        'cancel_claim_body' => 'This mode slot will open up again for other clan members. Unsubmitted progress will be lost.',
        'cancel_claim_confirm' => 'Cancel Claim',
        'promote_title' => 'Make :name a Co-Leader?',
        'promote_body' => 'Co-Leaders can accept join requests and remove members, but cannot disband the clan or change its leadership.',
        'promote_confirm' => 'Make Co-Leader',
        'demote_title' => 'Demote :name to member?',
        'demote_body' => 'They will lose access to join requests and to removing members.',
        'demote_confirm' => 'Demote',
        'transfer_title' => 'Transfer leadership to :name?',
        'transfer_body' => 'They become Leader and you step down to a regular member. You cannot undo this yourself - only the new Leader can hand it back.',
        'transfer_confirm' => 'Transfer Leadership',
        'disband_title' => 'Disband :name?',
        'disband_body' => 'Every member will be removed and this clan permanently deleted along with its war history. This cannot be undone.',
        'disband_prompt' => 'Type :name to confirm',
        'disband_confirm' => 'Disband Clan',
    ],

    'aria' => [
        'back_leaderboard' => 'Back to leaderboard',
        'emblem' => ':key',
        'color' => ':key',
        'member_actions' => 'Actions for :name',
    ],

    'role' => [
        'leader' => 'Leader',
        'co-leader' => 'Co-Leader',
    ],

    'roster' => [
        'last_seen' => 'seen :time',
        'never_seen' => 'never seen',
        'war_points' => ':points war pts',
        'joined' => 'joined :time',
    ],

    'attr' => [
        'name' => 'clan name',
        'tag' => 'clan tag',
    ],

    'error' => [
        'max_members' => 'Clan has reached the maximum of :max members.',
        'mode_taken' => 'This mode was just taken by another member.',
        'disband_during_war' => 'A clan cannot be disbanded while a Clan War is running. Finish the war first.',
        'disband_name_mismatch' => 'The clan name does not match.',
    ],

    // Notification messages to ANOTHER user (toast). Same pattern as friends.notify.*.
    'notify' => [
        'join_request' => ':name requested to join :clan',
        'join_accepted' => 'Your request to join :clan was accepted',
        'war_challenged' => ':clan challenged your clan to a Clan War',
        'war_accepted' => ':clan accepted your Clan War challenge',
        'war_declined' => ':clan declined your Clan War challenge',
        'leadership_received' => 'You are now the Leader of :clan',
        'promoted' => 'You were promoted to Co-Leader of :clan',
        'demoted' => 'You are a regular member of :clan again',
        'disbanded' => ':clan was disbanded by its leader',
        'identity_updated' => 'Your clan is now called :clan',
    ],
];

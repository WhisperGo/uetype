<?php

return [
    'standard' => 'Standard',
    'survival' => 'Survival',
    'survival_login' => 'Login to unlock Survival',
    'ghost' => 'Ghost',
    'ghost_pick' => '+ Race a Ghost',
    'ghost_change' => 'Change',
    'ghost_clear' => 'Clear',
    'ghost_login' => '+ Race a Ghost',

    'type_time' => 'Time',
    'type_words' => 'Words',

    'stat_left' => 'left',
    'stat_time' => 'time',
    'stat_wpm' => 'wpm',
    'stat_acc' => 'acc',
    'stamina' => 'stamina',

    'restart' => 'restart',
    'then' => 'then',
    'war_locked_restart' => 'restart locked (clan war)',
    'war_lock_label' => 'Clan War',
    // Deliberately NOT "cancel": leaving no longer cancels anything. The attempt is anchored
    // the moment the page opens and its clock keeps running, so a label promising otherwise
    // would talk a player into losing a slot they thought they were handing back.
    'war_lock_leave' => 'Back to Clan War (your attempt keeps running)',

    'caps_lock' => 'Caps Lock is on',

    // Touch devices only: the keyboard-summoning input is invisible, so without this nothing
    // on screen says the text has to be tapped before the keyboard will open.
    'tap_to_type' => 'Tap to start typing',
    'input_aria' => 'Typing test input',

    // Generic fallback: reserved for results the server judged genuinely impossible. The two
    // messages below exist because this one used to cover every rejection path, including
    // ones that are nobody's fault -- telling an honest player their run was "implausible"
    // when they had merely finished tests quickly is a bug in its own right.
    'result_rejected' => 'This session was rejected by server validation (implausible) and was not saved.',
    'result_rate_limited' => 'Too many results submitted in a short time. Wait a moment before finishing the next test.',
    'result_session_expired' => 'This test session expired or was started in another tab, so the result could not be saved. Please run the test again.',

    'aria' => [
        'pick_main_mode' => 'Pick main mode',
        'mode_standard' => 'Standard mode',
        'mode_survival' => 'Survival mode',
        'mode_ghost' => 'Ghost mode',
        'mode_config' => 'Mode configuration',
        'type' => 'Type :label',
        'duration_seconds' => ':seconds second duration',
        'words_count' => ':count words',
        'difficulty' => ':level difficulty',
        'pick_content_language' => 'Pick typing language',
    ],
];

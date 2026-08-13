<?php

return [
    'mode' => [
        'time' => 'Time · :config'.'s',
        'words' => 'Words · :config',
        'survival' => 'Survival · :config',
    ],

    'game_over' => 'game over',
    'survived' => 'survived',
    'wpm' => 'wpm',
    'stamina_depleted_at' => 'stamina depleted at :time',
    'new_personal_best' => 'new personal best',
    'pb' => 'PB :time',
    'pb_short_by' => ':seconds s short',
    'beat_ghost' => 'beat the ghost',
    'lost_ghost' => 'lost to the ghost',
    'vs_ghost' => 'vs :label (:wpm wpm)',
    'afk_not_recorded' => 'You were away for a long stretch of this test, so it was not saved to your history or stats.',

    'integrity' => [
        'clear' => 'Result accepted and eligible for records and the leaderboard.',
        'pending_title' => 'Result is being verified automatically',
        'pending_body' => 'The result is saved, but is not used for records, Ghost, or the leaderboard yet. Complete a 10-second speed check to verify this pace now.',
        'verify_speed' => 'Verify speed · 10 seconds',
        'auto_cleared' => ':count previously verified results were accepted automatically.',
    ],

    // Toast heading. Reads correctly for one unlock or several -- the names follow it as a
    // list, so no pluralised string is needed. The toast's own "view" link is notif.view.
    'achievement_unlocked' => 'Achievement unlocked',

    'stat' => [
        'avg_wpm' => 'avg wpm',
        'accuracy' => 'accuracy',
        'words' => 'words',
        'drain_events' => 'drain events',
        'consistency' => 'consistency',
        'characters' => 'characters',
        'difficulty' => 'difficulty',
        'raw_wpm' => 'raw wpm',
        'duration' => 'duration',
    ],

    'xp_earned' => 'xp earned',
    'xp_gained' => '+:amount XP',
    'xp_progress' => ':progress / :needed XP',
    'xp_level_up' => 'Level :from → :to',

    'chart_stamina' => 'stamina',
    'chart_performance' => 'performance',

    'play_again_title' => 'Play Again',
    'next_test_title' => 'Next Test',
    'retry_title' => 'Retry Test',
    'back_to_war' => 'Back to Clan War',

    'war' => [
        'heading' => 'War Contribution',
        'points' => ':points / :ceiling pts',
        'factor_pace_wpm' => 'pace :value wpm → :ratio× of :scale',
        'factor_pace_survival' => 'survived :value s → :ratio× of :scale s',
        'factor_accuracy' => 'accuracy :value% → :multiplier×',
        'not_counted_title' => 'Not counted toward the war',
        'not_counted_body' => 'This slot was already submitted, or the war ended before you finished. Your typing result is still saved.',
        'verification_pending_title' => 'War points are being verified',
        'verification_pending_body' => 'The slot is saved, but its points remain 0 while the result is being verified.',
    ],

    'error_heatmap' => 'error heatmap',
    'miss_count' => ':count wrong',

    'error_axis' => 'errors',
    'error_hint' => 'click a marker on the chart to see what went wrong',
    'error_at' => 'second :second',
    'error_expected' => 'needed :expected',
    'error_typed' => 'you pressed :actual',
    'error_skipped' => 'skipped — you moved on before typing this',
    'error_no_word' => 'unknown word',
    'error_legend_needed' => 'key you needed',
    'error_legend_pressed' => 'key you pressed',
    'error_none' => 'clean run — no mistakes',
];

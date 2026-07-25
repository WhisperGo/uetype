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
    'vs_record' => 'vs record :best',
    'beat_ghost' => 'beat the ghost',
    'lost_ghost' => 'lost to the ghost',
    'vs_ghost' => 'vs :label (:wpm wpm)',
    'afk_not_recorded' => 'You were away for a long stretch of this test, so it was not saved to your history or stats.',

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

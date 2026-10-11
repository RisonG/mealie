<?php

/**
 * Route loader. One file per round.
 *
 * The order is the order the rounds were written, with one exception: round 7 loads before round 6.
 * Round 6 owns /recipes/{slug} and round 7 adds literal second segments under the same prefix
 * (/recipes/shared/…, /recipes/exports, /recipes/create/…), and Laravel matches in declaration
 * order where FastAPI prefers literals regardless.
 *
 * Every path here is copied from the matching Python router in mealie/routes/. The /api prefix is
 * applied by bootstrap/app.php (apiPrefix), mirroring mealie/routes/__init__.py:20.
 */
foreach ([
    'round1',
    'round2',
    'round3',
    'round4',
    'round5',
    'round7',
    'round6',
    'round8',
    'round9-admin',
    'round9',
] as $group) {
    $file = __DIR__.'/api/'.$group.'.php';
    if (is_file($file)) {
        require $file;
    }
}

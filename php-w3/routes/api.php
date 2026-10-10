<?php

/**
 * Route loader. One file per round, in the order the rounds were written.
 *
 * Every path here is copied from the matching Python router in mealie/routes/. The /api prefix is
 * applied by bootstrap/app.php (apiPrefix), mirroring mealie/routes/__init__.py:20.
 */
foreach ([
    'round1',
    'round2',
    'round3',
] as $group) {
    $file = __DIR__.'/api/'.$group.'.php';
    if (is_file($file)) {
        require $file;
    }
}

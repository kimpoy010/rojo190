<?php

return [
    // Minimum wallet balance a player needs before the live stream is shown.
    'min_balance_to_view' => (float) env('POOL_MIN_BALANCE_TO_VIEW', 10),
];

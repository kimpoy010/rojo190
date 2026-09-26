<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('fight.{fightId}', function ($user) {
    return $user->status === 'active';
});

Broadcast::channel('event.{eventId}', function ($user) {
    return $user->status === 'active';
});

// Knowing the random cash-transaction code (embedded in the QR) is already
// the effective credential here, same as fight/event channels above.
Broadcast::channel('cash-transaction.{code}', function ($user) {
    return $user->status === 'active';
});

// A wallet balance is private — unlike the channels above, membership here
// isn't "knows a hard-to-guess identifier", it's "is this specific user".
Broadcast::channel('wallet.{userId}', function ($user, $userId) {
    return $user->status === 'active' && (int) $user->id === (int) $userId;
});

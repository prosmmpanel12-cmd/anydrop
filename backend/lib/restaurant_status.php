<?php
/**
 * Shared "is this restaurant open right now" computation.
 *
 * Previously duplicated independently in restaurants/list.php (inline) and
 * search/search.php (its own local compute_open_now()) — restaurants/menu.php
 * (the restaurant detail/menu screen) never computed it at all, which meant a
 * paused/closed restaurant showed no badge on its own detail page even though
 * Home cards and search results correctly showed one (bugs.md §6.3 follow-up).
 * Consolidated here so there's exactly one place this logic lives — matches
 * the "three places must show the same thing" requirement from bugs.md §6.1.
 *
 * Expects $r to be a `restaurants` row (associative array) with at least:
 * operational_status, opening_time, closing_time, working_days.
 *
 * Returns ['is_open_now' => bool, 'is_paused' => bool].
 */
function compute_restaurant_status(array $r, ?string $currentTime = null, ?int $currentDow = null): array
{
    if ($currentTime === null || $currentDow === null) {
        $now = new DateTime();
        $currentTime = $currentTime ?? $now->format('H:i:s');
        $currentDow = $currentDow ?? (int) $now->format('N');
    }

    $isOpenNow = false;
    if ($r['operational_status'] === 'open' && $r['opening_time'] && $r['closing_time']) {
        $days = explode(',', (string) $r['working_days']);
        $dayMatches = in_array((string) $currentDow, $days, true);
        $timeMatches = ($currentTime >= $r['opening_time'] && $currentTime <= $r['closing_time']);
        $isOpenNow = $dayMatches && $timeMatches;
    }

    // Distinguishes "closed because it's outside its fixed hours" from
    // "on-demand paused right now" so the app can show "Temporarily
    // unavailable" instead of a plain "Closed" for the latter, since the
    // restaurant could resume any minute. Only meaningful when $isOpenNow
    // is already false.
    $isPaused = in_array($r['operational_status'], ['busy', 'temp_closed'], true);

    return [
        'is_open_now' => $isOpenNow,
        'is_paused' => $isPaused,
    ];
}

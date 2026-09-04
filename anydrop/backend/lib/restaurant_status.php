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
 * operational_status, opening_time, closing_time, working_days. Reads
 * temp_closed_until too if present (migration 58) — every current
 * caller does `SELECT *`, so it's already on the row without any query
 * changes at the four call sites.
 *
 * $hasActiveClosureToday (§3, today.md 2026-08-28, migration 58) —
 * whether a restaurant_closures row (date_range or weekly_recurring)
 * covers right now. Default false keeps every pre-existing call of this
 * function byte-for-byte unchanged; callers that want scheduled
 * closures folded in pass the result of
 * lib/restaurant_closures.php's get_restaurants_with_active_closure()
 * (batch) or a single-restaurant equivalent. When true, it overrides
 * *both* flags straight to closed/paused regardless of opening hours or
 * operational_status — a scheduled holiday closes the restaurant even
 * if today's working hours would otherwise say open.
 *
 * Returns ['is_open_now' => bool, 'is_paused' => bool].
 */
function compute_restaurant_status(
    array $r,
    ?string $currentTime = null,
    ?int $currentDow = null,
    bool $hasActiveClosureToday = false
): array {
    if ($currentTime === null || $currentDow === null) {
        $now = new DateTime();
        $currentTime = $currentTime ?? $now->format('H:i:s');
        $currentDow = $currentDow ?? (int) $now->format('N');
    }

    if ($hasActiveClosureToday) {
        return ['is_open_now' => false, 'is_paused' => true];
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

    // Auto-expiry for the "closed until [date/time]" resume-time (§3,
    // migration 58) — once now() is past temp_closed_until, the pause
    // reads as over here even though the DB row's operational_status
    // itself still literally says temp_closed until the restaurant (or
    // a future cron) writes it back to 'open'. Deliberately does NOT
    // flip $isOpenNow to true here — this function has no write access
    // and no business guessing whether the restaurant actually wants to
    // reopen the instant the timer lapses; it only stops *misrepresenting*
    // an expired pause as still active. status-update.php remains the
    // only write path. Only applies to temp_closed — 'busy' has no
    // resume-time concept (status-update.php never accepts resume_at
    // for it).
    if ($isPaused && $r['operational_status'] === 'temp_closed' && !empty($r['temp_closed_until'] ?? null)) {
        $resumeAt = new DateTime($r['temp_closed_until']);
        if (new DateTime() >= $resumeAt) {
            $isPaused = false;
        }
    }

    return [
        'is_open_now' => $isOpenNow,
        'is_paused' => $isPaused,
    ];
}

<?php
/**
 * Item availability timing (today.md §1, migration 62).
 *
 * `menu_items.is_available` has always been a manual owner ON/OFF
 * toggle (the out-of-stock switch). `available_from`/`available_until`
 * add an optional *daily recurring* time window on top of that — e.g.
 * a breakfast item only orderable 7:00-11:00. Both are independent:
 * an item can be manually toggled off AND have a time window: it must
 * pass both checks to be orderable right now.
 *
 * Deliberately a single small function, not a class/service, mirroring
 * this codebase's own `compute_restaurant_status()` — same "pure
 * function of a row + the current time" shape, easy to call from
 * every surface (customer-facing menu, cart-sync, order creation)
 * without a shared object to wire up.
 */

/**
 * True if [$item] is currently orderable, combining the manual
 * `is_available` toggle with the optional `available_from`/
 * `available_until` time window.
 *
 * - Both columns NULL (the default — every item before this migration,
 *   and any new item that never sets a window): no time restriction,
 *   only `is_available` matters. This is what keeps every existing
 *   item's behavior unchanged.
 * - Only one of the two set: treated as no restriction (a half-open
 *   window has no well-defined meaning) — same "ignore incomplete
 *   config rather than guess" stance `compute_restaurant_status()`
 *   takes for its own optional fields.
 * - Both set, `available_from < available_until`: item is available
 *   only when the current time falls inside that same-day range
 *   (e.g. 07:00-11:00).
 * - Both set, `available_from > available_until`: an overnight window
 *   that wraps past midnight (e.g. 22:00-02:00) — available when the
 *   current time is at/after `available_from` OR before
 *   `available_until`.
 * - `available_from == available_until`: treated as no restriction
 *   (a zero-width window would mean "never available," which is what
 *   the plain `is_available` OFF toggle already exists for — this
 *   avoids a confusing dead state reachable only by a form typo).
 *
 * @param array $item A `menu_items` row (or any array with at least
 *                     `is_available`, `available_from`, `available_until`
 *                     keys — callers that only SELECT a subset should
 *                     include these three).
 * @param string|null $currentTime "HH:MM:SS" (or "HH:MM"); defaults to
 *                     the real current time. Accepting an override
 *                     keeps this testable without mocking the clock,
 *                     same as `compute_restaurant_status()`'s own
 *                     `$currentTime` param.
 */
function is_menu_item_available_now(array $item, ?string $currentTime = null): bool
{
    if (!$item['is_available']) {
        return false;
    }

    $from = $item['available_from'] ?? null;
    $until = $item['available_until'] ?? null;

    if ($from === null || $until === null || $from === '' || $until === '') {
        return true;
    }

    if ($from === $until) {
        return true;
    }

    $now = $currentTime ?? date('H:i:s');

    if ($from < $until) {
        return $now >= $from && $now < $until;
    }

    // Overnight wraparound.
    return $now >= $from || $now < $until;
}

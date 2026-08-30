<?php
/**
 * Restaurant Staff / RBAC — permission table (migration 63,
 * PENDING.md item 3).
 *
 * `lib/auth.php`'s `require_auth('restaurant')` now returns a `role`
 * key alongside `owner_id`/`owner_type` — `'owner'` for the
 * restaurant's own login (unchanged, full access), or one of
 * `restaurant_staff.role`'s values (`manager`/`kitchen`/`cashier`) for
 * a staff login. This file is the single place that says which role
 * can do what, so every endpoint's own gate is one line
 * (`require_restaurant_permission($owner, 'manage_menu')`) rather than
 * re-deriving the same role logic per file.
 *
 * Permission design (the actual "re-audit" decision, documented here
 * rather than scattered across 47 files):
 *
 * - `manage_staff`, `manage_bank_details` — owner only. Who can log in
 *   as staff at all, and the restaurant's own payout account, are the
 *   two things this project treats as "the owner, and only the owner."
 * - `manage_restaurant_profile`, `manage_menu`, `manage_closures`,
 *   `manage_offers_coupons`, `view_insights` — owner + manager. A
 *   manager is meant to run the restaurant's day-to-day configuration
 *   (menu, hours, promotions, profile) and see how it's performing,
 *   but not touch staff accounts or banking.
 * - `manage_orders` — owner + manager + kitchen + cashier. Every
 *   operational role needs to accept/reject/update orders; this is the
 *   actual job kitchen and cashier staff are hired to do, so the gate
 *   exists mainly for documentation/consistency rather than to exclude
 *   anyone.
 *
 * Deliberately NOT gated by role at all (left reachable by any
 * authenticated staff member, any role, same as today): plain list/
 * read endpoints — categories-list, menu-items-list, addon-groups-list,
 * closures-list, offers-list, coupons-list, orders-list, orders-detail,
 * banners-list, food-tags-list, profile-get, notifications, reviews
 * (viewing), fcm-token-update. None of these mutate anything or expose
 * something more sensitive than what any staff member handling walk-up
 * orders would already see on a shared terminal; gating every read
 * endpoint too would multiply this file's surface for no real security
 * benefit and risks locking out a role from something they need to see
 * to do their job (e.g. kitchen needs orders-detail).
 *
 * Not built this session (see doc's own "Genuinely still open"):
 * finer-grained permissions WITHIN a single endpoint — e.g.
 * menu-items-update.php currently needs `manage_menu` (owner/manager)
 * for its full body, even though only a slice of that endpoint (the
 * is_available toggle) is something a kitchen role might reasonably
 * want without full menu-editing rights. Splitting that out would need
 * the endpoint itself to branch on which fields the request body
 * touches, a real change to that file's own logic, not just a gate at
 * the top — left as a flagged follow-up rather than guessed at.
 */

const RESTAURANT_ROLE_PERMISSIONS = [
    'owner' => [
        'manage_staff', 'manage_bank_details', 'manage_restaurant_profile',
        'manage_menu', 'manage_closures', 'manage_offers_coupons',
        'view_insights', 'manage_orders',
    ],
    'manager' => [
        'manage_restaurant_profile', 'manage_menu', 'manage_closures',
        'manage_offers_coupons', 'view_insights', 'manage_orders',
    ],
    'kitchen' => ['manage_orders'],
    'cashier' => ['manage_orders'],
];

/**
 * True if [$role] (one of 'owner'/'manager'/'kitchen'/'cashier') is
 * allowed to perform [$permission]. An unrecognized role has no
 * permissions at all — fail-closed, same spirit as this project's
 * other auth checks (see `require_auth()`'s own header).
 */
function restaurant_role_has_permission(string $role, string $permission): bool
{
    return in_array($permission, RESTAURANT_ROLE_PERMISSIONS[$role] ?? [], true);
}

/**
 * Call after `require_auth('restaurant')`. Halts with 403 `forbidden`
 * (distinct from `require_auth()`'s own 401 `unauthorized`/403
 * `account_suspended` — this is "you ARE logged in, just not allowed
 * to do this specific thing") if the authenticated owner/staff's role
 * lacks [$permission].
 *
 * @param array $owner The array `require_auth('restaurant')` returned
 *                      (must include a 'role' key — always true for
 *                      any token created after migration 63).
 */
function require_restaurant_permission(array $owner, string $permission): void
{
    $role = $owner['role'] ?? 'owner';
    if (!restaurant_role_has_permission($role, $permission)) {
        respond_error('forbidden', 403, ['required_permission' => $permission, 'role' => $role]);
    }
}

/**
 * Staff Audit Trail (migration 64, PENDING.md §7's last remaining
 * checkbox). Writes one `audit_logs` row for a staff-account action
 * (create/update/role-change/activate/deactivate/delete) — NOT for
 * every restaurant action in general (order updates, menu edits,
 * etc. are out of scope for this specific trail; see doc for why).
 *
 * `actor_type` stays 'restaurant' and `actor_id` stays the
 * restaurant's own id, same "owner_id always means the restaurant"
 * convention already used everywhere else a staff-vs-owner token
 * exists (auth_tokens.owner_id, orders' own attribution, etc.) —
 * see migration 64's own header for the full reasoning. WHO actually
 * did it (owner or a named staff member) lives in $details instead,
 * via the acting $owner array's own staff_id/role/name.
 *
 * Call this from staff-create.php/staff-update.php/staff-delete.php
 * AFTER the write succeeds, never before — a failed mutation should
 * not produce an audit row implying it happened.
 *
 * @param array $owner The array require_auth('restaurant') returned
 *                      for the actor performing this action (not the
 *                      staff row being acted upon).
 * @param string $action One of the values listed in migration 64's
 *                        header (e.g. 'staff_created').
 * @param int $targetStaffId The `restaurant_staff.id` being acted on.
 * @param array $extra Optional extra context (e.g. changed fields,
 *                      old/new role) merged into details_json.
 */
function write_staff_audit_log(array $owner, string $action, int $targetStaffId, array $extra = []): void
{
    require_once __DIR__ . '/audit.php';

    $restaurantId = $owner['owner_id'];
    $actingStaffId = $owner['staff_id'] ?? null;
    $actingRole = $owner['role'] ?? 'owner';

    write_audit_log('restaurant', $restaurantId, $action, array_merge([
        'target_staff_id' => $targetStaffId,
        'acting_role' => $actingRole,
        'acting_staff_id' => $actingStaffId,
    ], $extra));
}

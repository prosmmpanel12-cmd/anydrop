# Handover — Support / Ticket System, Admin Side (2026-08-27)

Closes recall.md item 20 / PENDING.md item 9's admin-side scope, per
the app owner's own framing: "Support/Ticket system ka admin side
(naya migration chahiye)." Requested as the third item in a three-item
queue, done one at a time after item 1 (Restaurant Finance/Payout
Analytics, doc 47 — turned out to already be built) and item 2
(reported-review moderation queue, done outside this session).

## What's new

- **`backend/sql/52_migration_support_ticket_system.sql`** — two new
  tables:
  - `support_tickets` — `ticket_code`, polymorphic `raiser_type`/
    `raiser_id` (customer/restaurant/rider), optional `order_id`,
    `category` (doc 21 §2.3's 10-value list, verbatim), `status`
    (open/in_progress/resolved/closed), `priority` (normal/urgent),
    `assigned_admin_id`, `resolution_note`.
  - `support_ticket_messages` — the conversation thread; the raiser's
    own initial description is stored as message #1, not duplicated
    into the ticket row.
  - No new `admin_permissions` rows — `support_view`/`support_manage`
    were already seeded by migration 29 and sat unused until now
    (migration confirms both exist via a `SELECT`, doesn't assume).
- **`backend/lib/support.php`** (new) — `create_ticket()`,
  `add_ticket_message()`, `update_ticket_status()`, `assign_ticket()`,
  `fetch_ticket_with_messages()`. Same "one function everyone calls"
  shape as `lib/refunds.php`/`lib/ledger.php` — ticket + first-message
  creation is one transaction; every write fires `write_audit_log()`;
  admin replies and status changes fire `create_notification()` to the
  raiser (bell only — no push channel exists for anything yet, same as
  every other notification in this codebase).
- **`backend/admin/support.php`** (new) — Open/In Progress/Resolved/
  Closed tabs + Urgent filter + ticket-code search (list mode);
  full thread + reply-with-attachment + status actions + assign
  dropdown (detail mode); a "Log a Ticket" form for staff to record a
  phone/WhatsApp-reported issue, since no app can create one yet.
  Gated `support_view` (read) / `support_manage` (reply, act, log new).
- **`_layout_head.php`** — new "Support Tickets" nav item, in the
  Orders & Operations group next to Order Control/Analytics; docblock's
  `$activeNav` list updated.

## A scoping correction worth flagging

Doc 21 §4.15 lists admin ticket states as "Open / In Progress / Urgent
/ Resolved" — four items in one list. This migration deliberately does
**not** model Urgent as a fifth `status` value alongside the other
three. Urgent is something a ticket *is* while open or in progress, not
a stage it passes through and leaves — folding it into `status` would
make an urgent ticket's actual progress invisible in the same field.
Built instead as `status` (workflow state) + a separate `priority`
column, with the admin queue exposing both (status tabs + an Urgent
filter) so nothing in the original UX picture is lost, it's just not
conflated in the schema. See migration 52's own header for the same
note in more detail.

## What stays out of scope (flagged, not forgotten)

- **App-side ticket creation.** No Customer/Restaurant/Rider App has a
  "Help & Support" screen yet — every ticket today is staff-logged via
  `admin/support.php`'s form. `create_ticket()` takes `$raiserType`
  generically for exactly this reason: whichever app builds that
  screen first calls straight into the same function, no schema or
  lib change needed.
- **Doc 21 §2.8's order-screen "Having an issue?" shortcut** that
  should auto-create a ticket — needs the Customer App's order-detail
  screen touched, separate work.
- **Doc 21 §21's future AI Support Chat** — explicitly described there
  as sitting on top of this exact ticket architecture; this migration
  is the prerequisite, not that feature itself.
- **Push notifications on new replies** — uses the existing
  notification-bell-only path; FCM push isn't wired for any
  notification type in this codebase yet.
- **Item 10 (Customer Self-Service Refund Flow)'s stated dependency**
  ("should be integrated with the Support/Ticket system") — this
  migration makes that integration possible (a refund request could
  become a `refund_issue` ticket) but doesn't wire `lib/refunds.php`
  to `lib/support.php` itself; that's item 10's own follow-up, not
  assumed here.

## Needs a real machine, not this sandbox

Same standing limitation as every prior session — no PHP CLI, no live
DB, no browser here. Manual brace/paren balance-check done on both new
PHP files in place of `php -l` (support.php and admin/support.php both
balanced).

1. `php -l` on both new files, then run migration 52.
2. Log a ticket via the "Log a Ticket" form for a real customer/
   restaurant/rider id — confirm it lands in the Open tab and message
   #1 shows the description.
3. Reply as admin — confirm the raiser gets a notification bell entry
   referencing the ticket code, and the ticket resurfaces at the top of
   the Open tab (`updated_at` touched) even though status didn't change.
4. Start Work → Resolve — confirm Resolve is blocked with no resolution
   note entered (`promptResolutionNote()`'s JS + `update_ticket_status()`'s
   server-side check, tested from both directions: cancel the prompt,
   and submit a form with the hidden field manually cleared).
5. Close, then Reopen from Resolved and from Closed — confirm Closed
   has genuinely no forward transition (`update_ticket_status()`'s
   `$allowed['closed'] = []`) and the UI shows no buttons for it.
6. Assign to an admin, confirm the dropdown persists the selection on
   reload; unassign back to "— Unassigned —".
7. Attach a JPG on a reply, confirm it lands in
   `backend/uploads/support_attachments/` and renders as a thumbnail
   in the thread; try a >5 MB file and a non-image file, confirm both
   are rejected with the specific message and no message row is created.
8. Confirm a role with only `support_view` (no `support_manage`) can
   see the list and thread but gets no reply form, no status buttons,
   no assign dropdown, and no "Log a Ticket" card.

## Suggested next step

All three items from the app owner's original queue are now addressed
(payout analytics confirmed already-built, review moderation done
outside this session, support/ticket admin side built here). Per this
doc's own "what stays out of scope" list, the two most natural
follow-ups are:

- Whichever app (Customer, Restaurant, or Rider) is highest priority
  gets a "Help & Support" screen wired to `create_ticket()`.
- Item 10 (Customer Self-Service Refund Flow) can now actually satisfy
  its own stated dependency on this ticket system.

# Anydrop — Auto Bestseller/Discount Script + Git Push Cheat-Sheet

## 1. Auto Bestseller + Discount script

**File:** `backend/scripts/auto-update-bestseller-discount.php`

### What it actually does (read this before running)
- **Bestseller — real signal.** For each restaurant, it looks at
  `order_items` joined to `orders` where `status = 'delivered'`, sums
  quantity sold per menu item, and marks the top N (default 3) as
  `is_bestseller = 1`. This is genuine data once real orders exist.
- **Bestseller — fallback.** If a restaurant has **zero** delivered
  orders yet (true for any brand-new/test restaurant), there's nothing
  real to rank — the script instead marks that restaurant's first N menu
  items (by id) as bestseller, purely so the "Highly reordered" badge has
  something to show while testing. The script's output tells you which
  restaurants got real ranking vs. the fallback.
- **Discount — demo placeholder, not a real rule.** There is no signal in
  the database schema for "this item should be discounted" — that's a
  pricing decision, not something derivable from order history. This
  script randomly discounts a slice of each restaurant's non-bestseller
  items (default: 30% of them, at a flat 20% off) **just so the corner
  badge has something to show during testing.** Per the 2026-08-09
  decision in `docs/Status.md`, a real discount control (restaurant-side
  toggle, coupons, etc.) is separate future scope — don't mistake this
  script's output for real pricing.

### How it's different from `seed-test-data.php` / `seed-admin.php`
Those are **one-time** — they check if data already exists and refuse to
run twice. This script is **safe to re-run any time** — every run does a
full recompute (resets flags first, then reapplies), so you can re-run it
after real orders start coming in and the fallback logic will naturally
stop applying on its own.

### How to run it
Open in your phone browser (same pattern as the other scripts):
```
http://localhost:8080/anydrop/scripts/auto-update-bestseller-discount.php?key=SEED_ME
```

Optional tuning via query params:
| Param | Default | Meaning |
|---|---|---|
| `bestseller_top` | `3` | How many items per restaurant to mark bestseller |
| `discount_percent` | `20` | Flat % discount applied to the randomly-picked items |
| `discount_ratio` | `0.3` | Fraction (0–1) of each restaurant's remaining items that get a discount |

Example, more aggressive for a demo:
```
http://localhost:8080/anydrop/scripts/auto-update-bestseller-discount.php?key=SEED_ME&bestseller_top=5&discount_percent=25&discount_ratio=0.5
```

It prints a per-restaurant summary — e.g.:
```
Restaurant #1 (Spice Garden): 3 bestseller item(s) set [fallback — no delivered orders yet], 4 item(s) got 20% discount.
Done. Total: 3 bestseller flags set, 4 discount flags set across 1 restaurant(s).
```

**Unlike the seed scripts, do NOT delete this file after running it** —
it's meant to be re-runnable. Just remember it's gated behind
`?key=SEED_ME`, same as the others — change that key (or delete the file)
before a real public deploy on InfinityFree.

---

## 2. Git push cheat-sheet (copy any time you need to push a change)

Assumes your clone is at `~/anydrop` (confirmed earlier — `origin` points to
`https://github.com/prosmmpanel12-cmd/anydrop.git`, branch `main`).

### A) When I (Claude) hand you a new zip with changed files
1. Extract the zip somewhere separate first — **never straight into `~/anydrop`**,
   so you can see exactly what changed before overwriting anything:
   ```bash
   mkdir -p ~/anydrop-new
   unzip -o /storage/emulated/0/Download/<the-zip-name>.zip -d ~/anydrop-new
   ```
2. Copy only the specific changed files across (I'll always give you the
   exact list + exact `cp` commands — don't bulk-copy the whole folder,
   that risks overwriting local changes you haven't pushed yet).
3. Check what actually changed:
   ```bash
   cd ~/anydrop
   git status
   ```
4. Stage, commit, push:
   ```bash
   git add .
   git commit -m "short description of what changed"
   git push origin main
   ```

### B) Just the raw commands, no explanation (quick reference)
```bash
cd ~/anydrop
git status
git add .
git commit -m "message here"
git push origin main
```

### C) After pushing — always check the build
```
https://github.com/prosmmpanel12-cmd/anydrop/actions/
```
Wait for the yellow "running" dot to turn into a green check (pass) or
red cross (fail). If it fails, open the run and copy the error text back
here.

### D) If push says "Everything up-to-date"
This means git found **no changes** to commit — almost always because the
`cp` step didn't actually land the files in `~/anydrop` (wrong source path,
wrong destination path, or copied into the wrong clone folder — you have
several `anydrop*` folders under `~`, only `~/anydrop` is the real GitHub
clone). Run `git status` first — if it shows nothing under "Changes not
staged" and nothing under "Untracked files", the copy step needs to be
redone before commit/push will do anything.

### E) If you're not sure which local folder is the real clone
```bash
for d in ~/anydrop*; do
  if [ -d "$d/.git" ]; then
    echo "== $d =="
    git -C "$d" remote -v
    git -C "$d" branch --show-current
  fi
done
```
The one printing `origin  https://github.com/prosmmpanel12-cmd/anydrop.git`
and branch `main` is the real one — currently that's `~/anydrop`.

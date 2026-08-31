# Anydrop — Update Workflow Cheat Sheet (Termux)

**Purpose:** Every time Claude (chat) gives you an updated project zip, it
will have a **different filename** each time (e.g.
`anydrop_project_updated.zip`, `anydrop_project_v2.zip`, etc. — Claude
cannot reuse the exact same filename across separate zip exports). Instead
of asking Claude to spell out extract+copy+push steps every single time,
just run the one command block below — it always finds the **most
recently downloaded zip**, regardless of its name.

---

## One-time setup (already done, for reference only)

- Project lives at `~/anydrop_project` (Termux home).
- It's a git repo with `origin` pointing to
  `https://github.com/prosmmpanel12-cmd/anydrop`.
- `gh auth login` was already completed once — Termux stays logged in, no
  token/password needed for future pushes.

---

## Every time you get a new zip from Claude

1. Download the zip normally (it lands in `/storage/emulated/0/Download/`).
2. Open Termux and run this **entire block** as-is — don't edit filenames,
   it auto-detects the newest `.zip` in Downloads:

```bash
cd ~
ZIPFILE=$(ls -t /storage/emulated/0/Download/*.zip | head -1)
echo "Using zip: $ZIPFILE"
rm -rf ~/anydrop_extracted
unzip -o "$ZIPFILE" -d ~/anydrop_extracted
cp -rf ~/anydrop_extracted/* ~/anydrop_project/
cd ~/anydrop_project
git add -A
git commit -m "Update from Claude zip: $(date +%Y-%m-%d)"
git push
```

**Note on the zip structure:** Claude's zips have `customer/`, `restaurant/`,
`backend/`, etc. directly at the zip root — there is **no** wrapper folder
(not `anydrop_project/`, not the zip's own filename). So the copy step is
`cp -rf ~/anydrop_extracted/* ~/anydrop_project/`, not
`~/anydrop_extracted/<some-folder>/*`. If Claude ever changes this and
wraps everything in a subfolder again, it'll say so explicitly — otherwise
assume root-level, like above.

Also note `git add -A` (not plain `git add .`) — `-A` is required so
**deletions** are staged too (see the dedicated section below on why this
matters).

3. Watch the Termux output — confirm no errors on the `unzip`, `cp`, or
   `git push` lines.
4. Check the build: `https://github.com/prosmmpanel12-cmd/anydrop/actions`
   — a new run should trigger automatically (since `customer/**` or
   `.github/workflows/**` changed) and go green in a few minutes.

That's it — no need to re-explain the workflow to Claude in future chats,
just say "here's the new zip" and paste the block above.

---

## If something looks wrong after copying

- `git status` (inside `~/anydrop_project`) shows exactly which files
  changed — useful to sanity-check before committing if a merge ever looks
  suspicious.
- `config.php` (backend secrets) is **not** git-ignored yet per
  `docs/security.md`'s TODO list — double check it hasn't drifted from
  your local KS Web copy before blindly overwriting, since the zip's
  version may have placeholder values.

---

## When Claude deletes a file or folder — read this every time

**The `cp -rf` step only ever overwrites or adds files. It never deletes
anything on its own.** If Claude removes a file/folder from the project
(e.g. deleting `mipmap-anydpi-v26` to fix the round-icon bug on
2026-08-31), that folder simply isn't inside the new zip — but `cp -rf`
has no way to know it should be gone from `~/anydrop_project` too, so it
silently stays behind, stale, forever. This already happened once (the
adaptive-icon folder kept surviving three zips in a row until it was
caught manually via `git log -- <path>` and `ls`).

**Two ways to handle it, in order of preference:**

1. **Preferred — Claude tells you exactly what to delete.** When a fix
   involves removing something, Claude will give you an explicit `rm -rf`
   (or `rm`) line to run **before** the `cp -rf` step, e.g.:
   ```bash
   rm -rf ~/anydrop_project/restaurant/app/src/main/res/mipmap-anydpi-v26
   ```
   Run that first, then the normal update block above.

2. **Fallback — you suspect something wasn't cleaned up.** If a bug you
   reported as fixed keeps coming back after a push+build, it's often a
   leftover file `cp -rf` never removed. Check with:
   ```bash
   cd ~/anydrop_project
   git log --oneline -5 -- <path/you/suspect>
   ls <path/you/suspect> 2>&1
   ```
   If `ls` finds the file/folder even though Claude said it was deleted,
   remove it manually (`rm -rf <path>`), then `git add -A`, commit, push.

**Why `git add -A` and not `git add .`:** plain `git add .` only stages
new/modified files in and below the current folder — it does **not**
stage deletions in all cases depending on git version/config. `-A` always
stages everything: adds, modifications, **and** deletions. Always use
`-A` for this project so a manual `rm` you ran actually gets committed as
a deletion instead of just vanishing from your working folder while
GitHub still has the old copy.

---

## Reminder — this only updates the GitHub repo, not your phone's live backend

Pushing to GitHub updates the **Android app source** (which triggers a new
APK build) and the **`backend/` folder in the repo**. It does **not**
automatically update the PHP files KS Web is actually serving from on your
phone — that's a separate manual copy step (see `docs/05_Build_Pipeline.md`
and earlier chat history for the KS Web folder path). If a change touches
`backend/`, copy the updated files into KS Web's serving folder too, same
as you did for the rating-system migration.

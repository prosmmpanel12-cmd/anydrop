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
cp -rf ~/anydrop_extracted/anydrop_project/* ~/anydrop_project/
cd ~/anydrop_project
git add .
git commit -m "Update from Claude zip: $(date +%Y-%m-%d)"
git push
```

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
- The `cp -rf` step **overwrites** existing files with the zip's version
  but does **not** delete files that exist locally and aren't in the new
  zip. If Claude ever removes a file on purpose (rare), mention it and
  we'll add an explicit `rm` step for that file before running the block.
- `config.php` (backend secrets) is **not** git-ignored yet per
  `docs/security.md`'s TODO list — double check it hasn't drifted from
  your local KS Web copy before blindly overwriting, since the zip's
  version may have placeholder values.

---

## Reminder — this only updates the GitHub repo, not your phone's live backend

Pushing to GitHub updates the **Android app source** (which triggers a new
APK build) and the **`backend/` folder in the repo**. It does **not**
automatically update the PHP files KS Web is actually serving from on your
phone — that's a separate manual copy step (see `docs/05_Build_Pipeline.md`
and earlier chat history for the KS Web folder path). If a change touches
`backend/`, copy the updated files into KS Web's serving folder too, same
as you did for the rating-system migration.

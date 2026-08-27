# ANYDROP — Food Delivery Platform
## Master Blueprint & Phase Roadmap

**Version:** 1.0
**Created:** 2026-08-02
**Status:** Planning Complete — Ready for Phase 1

> **⚠️ PLAN CHANGED (2026-08-11):** The "Maps must be free" decision below
> (OSM/osmdroid + OSRM) has been superseded — **Google Maps is now the
> planned provider**, pending app name/`applicationId` finalization and
> Google Cloud billing setup. See
> `docs/12_Handover_H6_Map_PinDrop_Photo.md` → "Google Maps SDK migration
> plan" for the full reasoning, cost breakdown, and migration steps. This
> file's map-related sections below are historical context for *why* OSM
> was picked originally — not the current plan. Don't build against them.

---

## 1. What This Document Set Is

This is the single source of truth for building Anydrop. It covers:

- Final, locked-in tech stack (chosen for your constraints: no PC, GitHub Actions build, PHP+MySQL comfort, free hosting)
- Complete database schema
- Complete API contract
- All 4 apps' screen-by-screen behavior
- Live location tracking design (OpenStreetMap-based)
- Phase-by-phase build plan with **hard checkpoints** — no phase starts until the previous one is confirmed working

Read this file first. Other files go deep on one subsystem each.

| File | Covers |
|---|---|
| `00_README.md` | This file — stack, principles, phase map |
| `01_Database_Schema.md` | Every MySQL table |
| `02_API_Contract.md` | Every backend endpoint |
| `03_Live_Tracking.md` | GPS flow, OSM maps, Zomato-style animation |
| `04_Phase_Plan.md` | Detailed task breakdown per phase, with checkpoints |
| `05_Build_Pipeline.md` | GitHub Actions APK build setup, no-PC workflow |
| `Status.md` | Updated after every phase — current progress |

---

## 2. Constraints That Shaped Every Decision

These are real constraints you gave me. Every architecture choice below respects them — nothing here assumes a PC, a paid server, or a team.

| Constraint | Decision Made Because Of It |
|---|---|
| No PC available | Android apps written as standard Android Studio (Kotlin) projects, but **built via GitHub Actions** — push code, GitHub compiles the APK, you download it from the Actions tab or a Release. Zero local build tools needed. |
| Comfortable with PHP + MySQL | Backend = PHP (plain PHP with a small router, not a heavy framework — easier to host on free tiers) + MySQL. |
| Will host on InfinityFree (free) | Architecture avoids anything InfinityFree can't do (no WebSockets, no long-running processes, no real cron reliability). Live tracking uses **short-interval polling**, not sockets. Cron-like jobs (due-limit checks, cleanup) are triggered by a **request-based pseudo-cron** (an endpoint hit by a free external cron pinger like cron-job.org) since InfinityFree does not run background jobs reliably. |
| Maps must be free | OpenStreetMap via **osmdroid** (Android library) for map rendering + **OSRM** (free public routing engine) for route/ETA, instead of Google Maps. |

> **Honest flag, read this once:** InfinityFree is fine for Phase 1–3 (learning, demo, first real restaurants). It has no SLA, sometimes suspends accounts for "resource abuse" flags, and cannot run WebSockets or reliable cron. When you're ready to onboard real paying restaurants, budget ~₹150–400/month for a proper VPS (Hostinger, or a small DigitalOcean/Contabo box) or a free-tier server like Render (fine for PHP too). This is a scaling note, not a blocker — we build correctly now so switching hosts later is just a config change, not a rewrite.

---

## 3. Final Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Customer App | Android (Kotlin), min SDK 24 | Native = best performance, smallest APK, works with osmdroid cleanly |
| Restaurant App | Android (Kotlin) | Same codebase patterns as Customer app, shared modules |
| Rider App | Android (Kotlin) | Lightweight variant, battery-optimized GPS service |
| Admin Panel | **Web app** (PHP + plain HTML/CSS/JS, server-rendered) | You said "admin panel web se hi" — no app needed, works on any browser, easiest to maintain solo |
| Backend | PHP 8 (no framework — custom lightweight router + PDO for MySQL) | Matches your comfort zone, works on InfinityFree, easy to debug without a framework's learning curve |
| Database | MySQL 8 (InfinityFree-provided) | Standard, well-supported, you already know it |
| Maps / Location | OpenStreetMap tiles (osmdroid) + OSRM routing (free public demo server, replaceable later with self-hosted OSRM) | 100% free, no API key, no billing risk |
| Push Notifications | Firebase Cloud Messaging (FCM) — **free**, used only for push delivery, not data storage | FCM is free regardless of your DB choice; it's just Google's notification pipe |
| Image Storage | Stored on the PHP server's `/uploads/` folder to start; documented path to move to free object storage (Cloudflare R2 free tier) later | Keeps Phase 1 simple; swappable later |
| Android Build | GitHub Actions (`gradle assembleDebug` / `assembleRelease`) | No PC needed — cloud build |
| Version Control | GitHub (private repo recommended) | Needed for Actions anyway |

---

## 4. Non-Negotiable Principles (carried from your original prompt)

1. **Nothing hardcoded** — commission %, platform fee, due limit, OTP rules, GPS ping interval: all live in an `app_settings` table, editable from Admin Panel.
2. **Customer pays restaurant directly.** Platform never touches order money. Platform only tracks commission owed in a ledger.
3. **Restaurant owns riders.** No public rider signup — only restaurant-created rider accounts.
4. **Document/plan first, code second** — this doc set is finished and confirmed before Phase 1 code starts.
5. **Phase-gated development** — a phase is not "next" until you've tested the previous phase's APK/panel and typed something like "Phase X confirmed, go to Phase X+1". I will not silently continue past a phase boundary.

---

## 5. The Phase Map (high level — full detail in `04_Phase_Plan.md`)

Each phase produces something *you can actually see and test* — an APK you can install, or an admin page you can click through. No phase is "just backend with nothing to show."

| Phase | Deliverable | You Can Test |
|---|---|---|
| **Phase 0** | GitHub repo skeleton + working GitHub Actions build pipeline | A blank Android app APK builds successfully from GitHub, no PC used |
| **Phase 1** | Database schema live on InfinityFree + core backend APIs (auth, restaurants, menu read) | Hit API URLs in browser/Postman, see JSON responses |
| **Phase 2** | Customer App — Login, Home, Restaurant List, Restaurant Menu, Cart | Install APK, browse restaurants and add to cart (no real order yet) |
| **Phase 3** | Order placement + Restaurant App order management (accept/reject/prepare) | Place a test order from Customer App, see it appear in Restaurant App |
| **Phase 4** | Rider App + live GPS tracking + OTP delivery flow | Full order journey: place → restaurant accepts → rider delivers → OTP → delivered |
| **Phase 5** | Admin Panel (web) — restaurant approval, due ledger, settings, reports | Full admin control over the running system |
| **Phase 6** | Notifications (FCM), ratings/reviews, polish, edge-case handling | Feature-complete v1 |
| **Phase 7** | Performance pass, security hardening, deployment docs, launch checklist | Production-ready |

**Current phase: Phase 0 — in progress. Code generated, repo created, push + Actions build test pending. See `Status.md` for exact next step.**

---

## 6. What Happens At Each Phase Boundary

1. I build the phase (code + explanation of what was built and why).
2. I update `Status.md` with what's done, what's pending, known limitations.
3. You test it (install APK / open admin URL / hit API).
4. You reply with issues, or reply "confirmed" / "next phase".
5. Only then does the next phase begin.

This matches exactly what you asked for — no runaway building past what you've verified.

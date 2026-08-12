# Anydrop — No-PC Build Pipeline (GitHub Actions)

**Version:** 1.0

This explains exactly how you'll go from "Kotlin code" to "APK on your phone" without ever touching Android Studio locally.

---

## 1. The Workflow, End to End

1. Code lives in a GitHub repository (can be edited via GitHub's web editor, GitHub mobile app, or Claude Code / this chat pushing commits for you)
2. Every push to the repo triggers a **GitHub Actions** workflow (free for public repos, and free up to a generous monthly minute quota for private repos too)
3. The workflow spins up a temporary Ubuntu machine in the cloud, installs Java + Android SDK, runs `./gradlew assembleDebug`
4. The resulting `.apk` file is uploaded as a **workflow artifact** — downloadable as a zip from the Actions tab
5. You download it on your phone (via GitHub mobile app or browser), install it (enable "install from unknown sources" once), and test

No PC, no Android Studio, no local Gradle — everything compiles in GitHub's cloud runner.

---

## 2. Example Workflow File

This is the shape of `.github/workflows/build.yml` (actual file gets created in Phase 0):

```yaml
name: Build Anydrop APK

on:
  push:
    branches: [ main ]
  workflow_dispatch:   # lets you manually trigger a build from GitHub's UI too

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up JDK 17
        uses: actions/setup-java@v4
        with:
          distribution: 'temurin'
          java-version: '17'

      - name: Grant execute permission for gradlew
        run: chmod +x gradlew

      - name: Build Debug APK
        run: ./gradlew assembleDebug

      - name: Upload APK artifact
        uses: actions/upload-artifact@v4
        with:
          name: anydrop-debug-apk
          path: app/build/outputs/apk/debug/app-debug.apk
```

---

## 3. Getting the APK Onto Your Phone

**Option A (simplest):** Open the GitHub mobile app or mobile browser → repo → Actions tab → latest successful run → download the `anydrop-debug-apk` artifact (it's a zip containing the `.apk`) → extract/install.

**Option B (cleaner long-term):** Once a build is stable, tag it as a **GitHub Release** — the workflow can auto-attach the APK to a Release, giving you a direct, permanent download link (no zip extraction needed). We'll switch to this in a later phase once the debug loop is proven.

---

## 4. Signing (For Later, Not Phase 0)

`assembleDebug` produces a debug-signed APK — fine for your own testing. When you're ready to distribute more widely (or eventually to the Play Store), the workflow gets a signing step using a keystore stored as a **GitHub Secret** (encrypted, never exposed in logs) — this is a Phase 7 concern, documented here so it's not forgotten, not something to worry about now.

---

## 5. Why This Works For 3 Separate Apps

Customer, Restaurant, and Rider apps can each be:
- Separate GitHub repos, each with their own identical-shaped workflow (simplest to reason about, recommended given no local IDE to manage multiple projects in)
- OR one repo with 3 Gradle modules and 3 workflow files triggered by path filters (more advanced, possible later if repo management becomes tedious)

**Recommendation for Phase 0:** start with 3 separate repos. Simpler mental model, simpler Actions config, no risk of one app's build breaking because of another's code.

---

## 6. Cost

GitHub Actions is free for:
- Public repositories: unlimited minutes
- Private repositories: 2,000 free minutes/month (a debug APK build takes ~2-4 minutes, so this comfortably covers dozens of builds/day)

Given the sensitivity of restaurant/business logic, **private repos are recommended** — still comfortably within the free tier for solo development.

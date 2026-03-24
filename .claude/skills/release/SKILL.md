---
name: release
description: Analyze changes since last version tag, propose a semver bump, and create a new release tag after user approval.
disable-model-invocation: true
allowed-tools: Bash(git *), Read, Grep, Glob
argument-hint: [optional: force version, e.g. v1.2.0]
---

# Release — Semver Version Bump

Analyze changes since the last version tag and propose a new semver release.

## Steps

1. **Find the latest version tag:**
   ```
   git tag --sort=-v:refname | head -1
   ```
   If no tags exist, stop and tell the user to create an initial tag first.

2. **If an argument was provided** (e.g. `/release v1.2.0`), skip the analysis and use that version directly. Go to step 6.

3. **Gather changes since the last tag:**
   - Run `git log <last-tag>..HEAD --oneline` to list commits
   - Run `git diff <last-tag>..HEAD --stat` to see which files changed
   - Read the actual diffs for key changed files to understand the nature of changes

   If there are no commits since the last tag, stop and tell the user there is nothing to release.

4. **Classify the changes using these rules:**
   - **Patch** (X.Y.Z → X.Y.Z+1): bug fixes, config tweaks, minor UI adjustments, dependency updates, infra/deploy changes, documentation
   - **Minor** (X.Y.Z → X.Y+1.0): new features, new data points or stats, new UI sections or pages, new API capabilities, new loader functionality
   - **Major** (X.Y.Z → X+1.0.0): breaking database schema changes, breaking URL/route changes, major architectural rewrites, removal of existing features

5. **Propose the version bump.** Present to the user:
   - The current version
   - The proposed new version
   - A bullet-point summary of changes grouped by category
   - Your reasoning for the bump level
   - Draft release notes

   **Wait for the user to approve, adjust, or reject before proceeding.**

6. **After approval**, create and push the tag:
   ```
   git tag -a <new-version> -m "<release notes>"
   git push origin <new-version>
   ```

7. **Confirm** the tag was created and pushed. Remind the user that GitHub Actions will deploy automatically from this tag.

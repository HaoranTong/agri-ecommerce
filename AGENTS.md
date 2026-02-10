# AGENTS.md

This file provides project-specific instructions and context for coding agents working on the backend repo.

## Project Overview
- Backend: WordPress + WooCommerce + custom plugin (headless commerce)
- Repo path: e:\laragon\www\agri-ecommerce
- Plugin path: e:\laragon\www\agri-ecommerce\wp-content\plugins\myshop-core
- Branch: dev

## Shared Rules (Same as Frontend)
- Frontend and backend are separate repos. Each repo should have its own AGENTS.md.
- Work only on `dev`. Do not edit `trial` or `master`.
- `trial` only receives merges from `dev`; `master` only receives merges from `trial`.
- Two remotes: Gitee (primary) and GitHub (backup). Push to both.

## Environments
- Local dev: Laragon + Cloudflare tunnel `dev.fanbaoer.com` -> `127.0.0.1:80`.
- Staging (trial): `staging.fanbaoer.com`.
- Production: `fanbaoer.com`.

## Deployment Strategy (Must Follow)
Read and follow backend docs:
- `docs/DEPLOYMENT.md`
- `docs/WP_LOCK.md`
- `docs/WECHAT_PAYMENT_DEBUG.md`

Key points:
- Webhook deploy on Baota Linux panel.
- `trial` -> staging, `master` -> prod.
- Git whitelist only publishes self-owned code (plugin/theme/loco).
- WP-Lock aligns core + third-party plugins/themes to local truth source.

## Commit / Merge / Push Rules (Must Follow)
- Always work on `dev` and commit there.
- Before merging: ensure tests are green and docs are aligned.
- Backend deploy flow (no scripts in this repo):
  1) Push `dev` to both remotes.
  2) Merge `dev` -> `trial`.
  3) Push `trial` to both remotes to trigger webhook deploy.
- Never merge into `master` unless explicitly instructed.

## Workflow Expectations
- Any new/changed feature must include tests. All tests must be green.
- Backend tests: `php tests\run_rest_tests.php`.
- Update frontend/backend docs if API behavior or configs change.
- Do not create new docs; update existing ones.

## Feature Rules
- Referral vs agent must remain distinct.
- Referral binding: one direct referrer per user; no reverse binding.
- Referral rewards are points; points-to-commission withdrawal requires backend support.
- Gift card status rules must match user/admin docs.

## Release / Milestone
- Tag format: `YYYY-MM-DD-referral-promo-giftcard`
- Tag only after docs and tests are aligned.

## Safety / Constraints
- Avoid destructive git commands unless explicitly requested.
- Preserve untracked files unless instructed.

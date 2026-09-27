# .context/ — AI Context Files

This directory contains documentation of the Cinder9 Intranet codebase for use by AI coding assistants. Include these files in your prompt context when working on this project.

**Last updated:** September 2026

## Files

| File | Contents |
|---|---|
| `OVERVIEW.md` | Project purpose, tech stack, user roles, domain concepts |
| `ARCHITECTURE.md` | Directory structure, request/response patterns, auth system, frontend conventions |
| `BUSINESS_LOGIC.md` | Key workflows (purchase, sell, transfer, market scheduling), item classification, tier system |
| `ENDPOINTS.md` | Reference table of all PHP API endpoints in `db/` |

## Quick Reference

- **Tech:** PHP + MySQL (PDO) + vanilla JS/CSS, no framework
- **Admin user:** `user_id = -1`, `username = 'admin'` (hardcoded in auth logic)
- **API pattern:** `fetch()` → `db/**/*.php` → JSON `{success, error}`
- **Styling:** Single `styles/preview_styles.css` (~5000+ lines), dark theme (#1a1a1a)
- **Forum:** phpBB 3.x in `views/forum/`, embedded via iframe, auto-login via bridge
- **Item images:** `/images/UPPERCASE_CLASSNAME.PNG`
- **CSRF:** Auto-injected via `fetch()` wrapper in `header.php` — all POST requests include `X-CSRF-Token` header

## Usage Tips

1. **For feature work:** Read `OVERVIEW.md` + `ARCHITECTURE.md`
2. **For DB changes:** Read `ENDPOINTS.md` + the relevant `db/` subdirectory
3. **For debugging:** Read `BUSINESS_LOGIC.md` for workflow understanding
4. **For new pages:** Follow the include pattern in `ARCHITECTURE.md` (connection → auth → header → content → footer)
5. **For new endpoints:** Follow the backend pattern in `ARCHITECTURE.md` (connection → auth check → CSRF → parse input → transaction → log → response)
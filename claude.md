# General Rules

**If any questions are raised during a task, ask me before acting.**

**You will be EXTREMELY critic with my ideas and suggestions. You will never be afraid of hurting my feelings, and you will think on every idea or suggestion I give you, pondering if it's the best. Don't start an answer with a compliment.**

**If I end my request with a question, ANSWER THE QUESTION!**

**Every time I finish a sentence with `wdyt`, it means "what do you think?", and you ANSWER it HONESTLY before anything else**

---

# Versioning

- App version lives in `api/version` (plain text, no trailing newline) — read at runtime by `MagratheaPHP::AppVersion()` for the `/version` endpoint.
- Changelog lives in `api/changelog.md` — parsed by the `changelog` endpoint (5 most recent versions).
- `docs/openapi.yaml` also has a `version:` field near the top that should be kept in sync with `api/version`.

When bumping the version, update all three.

---

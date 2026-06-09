---
name: graphify-refresh
description: Refresh Graphify output only when DaengtisiaMS project structure changed significantly.
---

# Graphify Refresh Skill

Use only when:
- A new module was added.
- Many files were renamed or moved.
- A sprint changed architecture significantly.
- graphify-out is missing or outdated.
- User explicitly asks to refresh Graphify.

Rules:
- Do not refresh Graphify for small bug fixes.
- Do not commit graphify-out if it is ignored by .gitignore.
- After refreshing, read only graphify-out/GRAPH_REPORT.md.
- Summarize architecture changes briefly.

Preferred command:
- graphify .
- or /graphify . if supported by the coding assistant

After running:
- Check graphify-out/GRAPH_REPORT.md exists.
- Report node/edge count if available.
- Do not dump graph content.
- Use it only as navigation.

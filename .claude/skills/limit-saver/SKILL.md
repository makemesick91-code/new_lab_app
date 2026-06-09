---
name: limit-saver
description: Use this skill for any DaengtisiaMS task to reduce token usage, avoid broad scanning, and enforce minimal patch workflow.
---

# Limit Saver Skill

Use this workflow:

1. Inspect only the files mentioned by the user.
2. If file target is unclear, use rg/grep with specific keywords.
3. Use git status and git diff before opening many files.
4. Use route:list grep for route-related tasks.
5. Read only relevant snippets.
6. Make minimal patches.
7. Do not refactor unrelated code.
8. Do not scan vendor, node_modules, storage, bootstrap/cache, public/build, or graphify-out fully.

Before editing:
- plan max 5 bullets
- file list
- test plan
- risk

After editing:
- changed files
- short summary
- exact test commands
- manual checks

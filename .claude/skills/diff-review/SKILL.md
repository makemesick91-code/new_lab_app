---
name: diff-review
description: Use when reviewing git diff with minimal token usage.
---

# Diff Review Skill

Review only current git diff.

Focus:
1. Bugs
2. Authorization/policy issues
3. Branch context leaks
4. Repository binding problems
5. Route naming mismatch
6. Test failures
7. Unsafe database changes

Do not scan the full project.
Use git diff --stat first.
Then inspect only changed files.
Output only actionable findings and minimal patch suggestions.

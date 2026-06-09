---
name: graphify-navigator
description: Use Graphify output as a lightweight architecture map before touching DaengtisiaMS Laravel files.
---

# Graphify Navigator Skill

Use Graphify only as navigation.

Rules:
1. Do not read all graphify-out files.
2. Start from graphify-out/GRAPH_REPORT.md only.
3. Do not parse huge graph JSON files unless explicitly requested.
4. Use Graphify to identify likely folders/modules.
5. Verify exact findings with rg/grep and direct code inspection.
6. Open only files directly related to the task.
7. If GRAPH_REPORT.md is cluster-only, treat it as architecture context only.

Recommended workflow:
1. Read CLAUDE.md or AGENTS.md if needed.
2. Read graphify-out/GRAPH_REPORT.md only.
3. Identify relevant module cluster.
4. Use rg "keyword" app routes database resources tests.
5. Read only matched files.
6. Produce concise findings.

Output:
- Graphify context used
- Relevant folders
- Files verified directly
- Files not verified
- Next action

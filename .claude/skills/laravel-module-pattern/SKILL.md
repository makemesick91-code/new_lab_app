---
name: laravel-module-pattern
description: Use when creating or modifying DaengtisiaMS Laravel modules.
---

# Laravel Module Pattern Skill

Follow existing modular monolith pattern:

Controller → FormRequest → Service → Interface → Repository → Model → Policy → Blade → Test.

Rules:
- Keep controller thin.
- Put orchestration in Service.
- Put queries in Repository.
- Bind Interface to Repository in RepositoryServiceProvider.
- Protect write actions with Policy and permission.
- Keep route names consistent.
- Keep branch-aware queries.
- Add or update focused feature tests.
- Do not create unrelated abstractions.

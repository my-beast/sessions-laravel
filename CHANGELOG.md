# Changelog

## 0.1.1 - not released yet

- A signed-in person's visit to a page without the `auth` middleware is their journey step. Nothing had loaded the
  user when the step was made, so the visit was taken for a guest's and not kept. The id now comes from the session,
  still without a lookup. Such a step has no `subject_since`: only a loaded user tells when they joined.
- `mybeast:sessions-flows`' and `mybeast:sessions-flush`'s descriptions name My Beast Sessions, not an old host.

## 0.1.0 - 2026-11-01

The first release.

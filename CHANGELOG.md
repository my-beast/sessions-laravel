# Changelog

## 0.1.2 - 2026-11-17

- The README says where each `.env` value comes from (Secrets → the environment → Download .env; a key made by hand
  on the project's Settings → Keys) and which environment a copy of the app sets. It shows `clientConfig()` handed to
  the page in a Blade layout, the page's origin listed on the project, the yes asked for by your app (a migration and
  a route of yours), and a route of yours for a fresh token when a page stays open longer than its token.
- The schemas' copy follows the service's contract: a chunk's upload carries the browser's clock (`sent`), and a
  `press` is named by what its control says to the person - for a checkbox, a radio or a select its label's words,
  never its value - and a press on a label is told once.

## 0.1.1 - 2026-11-01

- A signed-in person's visit to a page without the `auth` middleware is their journey step. Nothing had loaded the
  user when the step was made, so the visit was taken for a guest's and not kept. The id now comes from the session,
  still without a lookup. Such a step has no `subject_since`: only a loaded user tells when they joined.
- `mybeast:sessions-flows`' and `mybeast:sessions-flush`'s descriptions name My Beast Sessions, not an old host.

## 0.1.0 - 2026-11-01

The first release.

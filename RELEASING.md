# Releasing

## Versioning

Versions follow [calendar versioning](https://calver.org/) in the form
`YYYY.MM.PATCH` and are tagged as `vYYYY.MM.PATCH`:

- `YYYY.MM` is the month the release reaches production.
- `PATCH` starts at `0` every month and goes up by one with each further
  production release that month, so `v2026.10.0` is followed by `v2026.10.1`.

A version exists only once it runs in production. Merging to `master`
deploys to staging and is not a release.

The ZmierzymyCzas repositories (eventflow-platform, eventflow-server,
biuro_zawodow-client, biuro_zawodow-api and central-mailer) all use this
scheme, but each is versioned on its own: a release here does not imply a
release of any other repository.

## Changelog

[CHANGELOG.md](CHANGELOG.md) follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Every pull request
with a notable change adds a line under `## [Unreleased]`, in the group that
fits: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` or `Security`.

- Write for someone who uses or integrates with this API, not as a
  commit message: say what changed for them.
- Refactors, tests and CI-only changes do not need an entry.
- A change that breaks a contract another repository relies on starts with
  **BREAKING:** and says what the other side has to do.
- When a release needs a minimum version of another repository, say so in its
  section, e.g. _Requires eventflow-server 2026.10.0 or later._

## Cutting a release

1. **Release PR.** On a branch named `release/vYYYY.MM.PATCH`, edit
   `CHANGELOG.md`:
   - rename `## [Unreleased]` to `## [YYYY.MM.PATCH] - YYYY-MM-DD`, using the
     planned production deploy date, and add a new empty `## [Unreleased]`
     above it;
   - update the links at the bottom of the file (`[Unreleased]` compares from
     the new tag, and the new version gets its own link).

   Merge it to `master` like any other pull request.

2. **Tag.** Tag the merge commit and push the tag:

   ```sh
   git switch master && git pull
   git tag -a vYYYY.MM.PATCH -m "vYYYY.MM.PATCH"
   git push origin vYYYY.MM.PATCH
   ```

   The [Release workflow](.github/workflows/release.yml) publishes a GitHub
   Release whose notes are that version's changelog section. It fails if the
   section is missing, so a tag never ships without notes.

3. **Deploy the tag.** In GitHub, go to _Actions → Deploy API → Run workflow_,
   choose the tag under _Use workflow from_ and set the target to
   `production`. Leave `migration_mode` at `run` unless the release notes say otherwise, and type `DEPLOY_PRODUCTION` to confirm. Production always runs from a tag, never
   from a branch, so what is deployed is exactly what the release notes
   describe.

4. **Verify.** Log in on production and open an event list to confirm the API answers
   and the main flow works.

## Hotfixes

If `master` holds nothing that should stay out of production, fix it there
and cut the next `PATCH` as above.

Otherwise branch `hotfix/vYYYY.MM.PATCH` from the tag in production, open the
fix as a pull request against that branch, then cut the release from it: add
the changelog section there, tag the branch's head and deploy that tag. Merge
the hotfix branch back into `master` afterwards so the fix and its
changelog entry are not lost.

## Rolling back

Deploy the previous tag to production the same way as in step 3. This
restores code only: a release that ran database migrations needs a plan for
the schema too, written into its changelog section before it ships.

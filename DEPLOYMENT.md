---
contract_version: 2
kind: library                       # a Composer package (type quicksilver-script) that customer sites install; nothing runs from this repo
service: quicksilver-pushback

trigger: tag                        # a semver tag is picked up by Packagist; sites get it on their next `composer update`
merged_implies_deployed: false      # merging to main changes only `dev-main`; customer sites require `^2` (stable tags)
other_paths_to_prod:
  - "any site that requires `dev-main` / `dev-master` gets branch heads directly (Packagist lists both; master is a stale branch) [inferred: no evidence any site does]"
  - "re-pointing or deleting a tag changes what new installs resolve to (tags are unprotected)"

pipeline:
  system: other                     # no build or test CI; Packagist reads the repo itself
  workflow: null                    # only .github/workflows/service-catalog.yaml (Backstage upload) exists
  jobs_in_order: []
  typical_minutes: null             # TODO(verify-on-deploy): time from tag push to the version appearing on packagist.org
  minutes_by_change: {}
  watch: "https://packagist.org/packages/pantheon-systems/quicksilver-pushback.json lists the new version with source.reference = the tag's commit"
  concurrency_group: null
  cancel_in_progress: false
  blocked_by_waiting_run: null
  approval_gate: null

repo_properties:
  observed_at: 2026-11-10
  trust_level: TL3
  ccb_status: in-ccb
  owner: developer-experience
  repo_type: tool

gating_checks:
  required: ["Wiz IaC Scanner", "Wiz Vulnerability Scanner", "Wiz Data Scanner"]   # org TL3 ruleset only; no repo ruleset
  approvals: {required: 1, code_owner: true, last_push_approval: false, strict_up_to_date: true, merge_queue: false}
  run_but_not_required: []          # there are no tests
  conditional: []
  tags_protected: false
  code_owners: "@pantheon-systems/developer-experience"

environments: []

release:
  mechanism: "push a semver tag (and a GitHub release by hand); Packagist picks it up [inferred: Packagist's GitHub hook; it lists recent branches as dev-* versions, so it is syncing]"
  latest: "2.1.1 (849c57c, 2023-01-03); Packagist 2.1.1 source.reference = 849c57c"   # verified 2026-11-10
  unreleased: "none: push-back.php, lean-repo-utils.php and composer.json are identical between 2.1.1 and main"   # verified
  never: "move or delete a published tag (Packagist and Composer caches, sites' composer.lock reference the commit)"

consumers:
  - repo: "customer sites (public Packagist package, ~35k installs a month, 3.3M total)"   # verified from packagist.org stats 2026-11-10
    pin: "the site's composer.json (`^2`) and composer.lock (exact commit)"
    bumped_by: "the site's own `composer update` (developer, CI, or Pantheon Integrated Composer / Autopilot updates) [inferred]"
  - repo: terminus-build-tools-plugin
    pin: "src/Commands/ProjectCreateCommand.php: `composer require --no-update pantheon-systems/quicksilver-pushback` (unconstrained) on `build:project:create`; assets/pantheon.yml wires push-back.php into `sync_code`"
  - repo: "example-drops-8-composer, example-wordpress-composer (PR-workflow starter repos named in the README)"   # not re-checked

deployed_when:
  - "packagist.org lists the new tag with source.reference equal to the tag's commit"

health:
  soak_minutes: null                # delegated: runs on customer sites as a Quicksilver sync_code hook
  baseline: null
  checks:
    - name: Packagist has the release
      kind: command
      run: "curl -s https://packagist.org/packages/pantheon-systems/quicksilver-pushback.json | jq -r '.package.versions[\"<tag>\"].source.reference'"
      healthy: "equals `git rev-parse <tag>^{commit}` (2.1.1: 849c57c31c678ed023ccdb2a7bde9ab14beb9c22, verified)"
    - name: customer reports
      kind: delegate
      run: "issues on this repo and #ask-hosting; there is no telemetry for Quicksilver script failures that this repo can read"
      healthy: "no new reports after a release"
  known_baseline_errors: []
  dashboards: []
  alerts: []

rollback:
  options:
    - when: "a new tag is bad"
      kind: retract-and-repin
      steps: "release a new patch tag whose code is the previous good tag (revert PR, merge, tag), so `^2` resolves to it; affected sites run `composer update pantheon-systems/quicksilver-pushback`. Don't delete the bad tag"
      time_to_rollback_minutes: 30  # inferred: one PR approval + tag + Packagist sync; sites that already updated stay on the bad version until they update again
  not_restored: ["sites that already ran composer update keep the bad version in composer.lock until they update again"]
  one_way_changes: ["pull requests or branches the script already pushed to customers' external Git repos"]
  currently_blocked: null
  last_exercised: null
  capture_before_merge: "git ls-remote --tags https://github.com/pantheon-systems/quicksilver-pushback | sort -V -k2 | tail -1   # the current latest tag"
  who_can: ["@pantheon-systems/developer-experience (1 code-owner approval for the revert PR; anyone with write can push the tag)"]
  verify: "packagist.org lists the new tag with the expected source.reference"

escalation:
  slack: "#ask-hosting"
  owner_team: developer-experience
  paged: false

preflight:
  - "gh auth status"
  - "outbound HTTPS to packagist.org (public, no auth)"

automation_blockers:
  - blocker: "no tests or CI at all; a release is untested"
    clears_when: "a CI workflow lints and tests push-back.php, and it's a required check"
  - blocker: "no signal for the script's health on customer sites"
    clears_when: "TODO(owner): decide whether one is needed for a script that hasn't changed since 2023"
  - blocker: "tags are unprotected"
    clears_when: "a tag ruleset blocks updating or deleting release tags"

verified:
  at: null
  by: null
  change: null
  running_digest: null
---

# Deployment

`quicksilver-pushback` is a public Composer package (type `quicksilver-script`). Customer sites using a Terminus Build Tools PR workflow install it, and Pantheon runs its `push-back.php` as a `sync_code` Quicksilver hook to push dashboard commits back to the site's external Git repo. **Nothing here builds or deploys.** A release is a tag that Packagist picks up; a site gets it the next time that site runs `composer update`.

## How a change reaches production

1. **Merge to `main`.** One code-owner approval (org TL3 ruleset). There is no CI except the Backstage catalog upload, so nothing tests the change.
2. **Tag a release** (semver, e.g. `2.1.2`) on `main` and create a GitHub release by hand. Packagist picks the tag up [inferred: Packagist syncs this repo through its GitHub hook; it lists recent branches as `dev-*` versions]. The latest release is `2.1.1` (2023-01-03), and `main`'s code is identical to it.
3. **Sites update.** Sites require a `^2` constraint (the README says `composer require pantheon-systems/quicksilver-pushback:^2`; Build Tools runs it unconstrained, so Composer writes a caret constraint on the latest tag [inferred]) and pin the exact commit in `composer.lock`, so they move only when they run `composer update` themselves (or Integrated Composer / Autopilot does it for them [inferred]). New Build Tools projects get the latest tag at creation.

TODO(verify-on-deploy): on the next release, time the gap from tag push to Packagist listing it.

## How to confirm it deployed

```bash
curl -s https://packagist.org/packages/pantheon-systems/quicksilver-pushback.json \
  | jq -r '.package.versions["<tag>"].source.reference'
git ls-remote https://github.com/pantheon-systems/quicksilver-pushback "refs/tags/<tag>*"   # use the ^{} line if the tag is annotated
```

The two must match. On 2026-11-10, `2.1.1` resolved to `849c57c31c678ed023ccdb2a7bde9ab14beb9c22` in both.

There's no way to see which version a given customer site runs from here; that's in the site's `composer.lock`.

## How to confirm it's healthy

Delegated: the script runs on customer sites, and nothing in this repo can observe it. After a release, watch this repo's issues and `#ask-hosting` for reports of dashboard commits not reaching the external repo. No dashboards or alerts exist.

## Rollback

Capture the current latest tag before releasing (`git ls-remote --tags https://github.com/pantheon-systems/quicksilver-pushback | sort -V -k2 | tail -1`).

1. Revert the change on `main` (PR, one code-owner approval) and tag a new patch release, so `^2` resolves to good code again. **Don't delete or move the bad tag**: Packagist, Composer caches and sites' `composer.lock` files reference it.
2. Sites that already updated stay on the bad version until they run `composer update pantheon-systems/quicksilver-pushback` again. For a serious problem, tell affected customers to do so.

Not undone: PRs or branches the script already pushed to customers' external repos. Rollback has never been exercised. Verify with the Packagist check above.

## Escalation

`#ask-hosting` (developer-experience). Nobody is paged.

## Suggested pipeline changes

- Add a minimal CI (PHP lint, and a test of `push-back.php` against a fixture repo) and make it a required check.
- Add a tag ruleset that blocks updating or deleting release tags.
- Delete the stale `master` branch (Packagist still offers it as `dev-master`).

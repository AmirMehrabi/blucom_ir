# Production deployment

`master` has a GitHub Actions workflow in `.github/workflows/ci.yml`. GitHub
currently reports a billing lock that prevents its jobs from starting. Until
that account issue is resolved, a repository **push** webhook triggers the
server pipeline. The receiver verifies GitHub's HMAC and runs `deploy.sh` for
the pushed commit. The script skips commits no longer at the head of `master`,
runs the PHP test suite, builds assets, and switches only after all steps pass.

## GitHub webhook

Repository **Settings → Webhooks → Add webhook**:

- Payload URL: `https://admin.blucom.ir/internal/github-deploy`
- Content type: `application/json`
- Secret: the value in `/var/www/html/blucom-deploy/shared/webhook-secret` on the server
- Events: **Just the push event**
- Active: checked

The secret is created on the server and must never be added to Git. The server
returns 202 for a successful deployment request, 204 for an irrelevant signed
event, and 401 for an invalid signature. A 202 means the job was queued; check
`/var/www/html/blucom-deploy/logs/deploy.log` for its result.

## Layout and recovery

- `releases/<sha>` is an immutable application checkout with its own vendor,
  compiled build, and Laravel cache.
- `shared/.env`, `shared/storage`, and `shared/assets` survive releases.
- `current` is switched atomically after build and migration. Nginx serves
  `current/public` and uses `$realpath_root` for PHP requests.
- The prior release stays on disk. A failed health check restores its symlink.
- The original `/var/www/html/blucom_ir` checkout remains available as the
  pre-migration backup until operations confirms it can be retired.

Database migrations run before switching the web root. New migrations must be
compatible with the currently serving release because a deployment or rollback
can leave old code running against the migrated schema. This setup prevents
deployment interruption for compatible changes. Long-running PHP jobs would
need separate restart or drain handling if added later.

To redeploy the current `master` head manually:

```sh
sha=$(git ls-remote git@github.com:AmirMehrabi/blucom_ir.git refs/heads/master | cut -f1)
/var/www/html/blucom-deploy/current/deploy/deploy.sh "$sha"
```

## Media storage ownership

The deployment script uses passwordless `sudo install -d` to ensure the shared
`storage/app/ivr` root belongs to `www-data:www-data` with mode `2775`. The
deployment account must be authorized for this infrastructure operation. PHP
creates announcement/IVR subdirectories and converts uploaded audio; FreeSWITCH
reads the published WAVs through the existing shared local disk.

When repairing a previously operator-owned media tree, back up its permissions,
then change its existing directories to `www-data:www-data` with mode `2775`.
Do not run upload validation as root: use `sudo -u www-data`. Do not change the
owner or permissions of recording spools as part of an IVR-folder repair.

On 2026-10-01, the IVR root and existing directories were repaired after PHP could
not create `announcements/1/2`: their previous mode was `2755` and their owner was
`ammir`. The permission backup is under
`/home/ammir/deploy-backups/ivr-permissions-20261001/permissions.before`.

A production probe ran from the application directory as `www-data`, stored and
converted an announcement in the previously failing number directory, and
verified 16 kHz mono WAV output readable by `freeswitch`. Only its newly generated
probe file was deleted; saved number policies and existing audio were preserved.

## Customer portal activation

The new Customer account boundary uses `my.blucom.ir`, a separate `customer`
guard, and a host-only customer session cookie. The Nginx template includes
the hostname; DNS and TLS certificate coverage must be verified before applying
it. Run the additive customer-account migration and refresh application caches
through the normal deployment. Existing accounts/resources are not converted.
Follow [CUSTOMER_TENANCY.md](../docs/CUSTOMER_TENANCY.md) for the complete
activation, ownership-review, and real-call checklist. No live ingress or
FreeSWITCH configuration was changed during implementation.

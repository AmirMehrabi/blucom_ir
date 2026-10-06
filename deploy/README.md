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

Provision the shared `storage/app/ivr` root once from an administrator shell:

```sh
sudo install -d -o www-data -g www-data -m 2775 /var/www/html/blucom-deploy/shared/storage/app/ivr
```

The deployment script checks that this directory belongs to `www-data:www-data`
with mode `2775` before building a release. It does not run `sudo`. The webhook
service retains `NoNewPrivileges=true`, which blocks privilege escalation for
the receiver and its deployment subprocesses. PHP creates announcement/IVR
subdirectories and converts uploaded audio; FreeSWITCH reads the published WAVs
through the existing shared local disk.

If an older deployed script fails with `sudo: The "no new privileges" flag is
set`, provision the directory above, then run the updated `deploy/deploy.sh`
from a checkout containing this fix with the current `master` SHA. The webhook
runs the script under `current`, so deploying the fixed commit through that old
script alone will repeat the error. After the updated script successfully
switches `current`, subsequent webhook deployments use it automatically.

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
Follow the [release checklist](../docs/RELEASE_CHECKLIST.md) for customer
activation, ownership review, and real-call validation. The subscription
[docs index](../docs/README.md) records implemented inventory and remaining checkout work. No live ingress or
FreeSWITCH configuration was changed during implementation.

## Disk space

The deploy script requires at least 1 GiB available on the release volume before
installing dependencies or migrating. `DEPLOY_MIN_FREE_KB` can raise this limit.
It removes the new release's generated `node_modules` after building assets;
production serves the compiled assets and PHP vendor dependencies remain intact.
For older releases, reclaim only generated Node build dependencies and package
caches after checking paths. Preserve shared storage, recordings, IVR media,
database backups, source/vendor and current/rollback release links.

## Recovery record — 2026-10-06

Production was still pinned to `d56047c`, whose script invoked sudo under the
hardened webhook. Bootstrapping the corrected script from a fresh master checkout
with `setpriv --no-new-privs` deployed `0057f79` successfully; the service retained
`NoNewPrivileges=yes`. The IVR directory already had the required permissions.
A full disk also blocked Composer; reclaiming generated `node_modules` from old
releases allowed the deployment, with rollback code/vendor/assets retained.
See the [production verification record](../docs/RELEASE_CHECKLIST.md) for backups,
checks and the pre-existing FreeSWITCH availability/log-retention issues.

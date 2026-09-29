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

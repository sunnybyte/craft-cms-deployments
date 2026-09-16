# Changelog

## 1.0.0 - 2026-09-15

- Initial release.
- Sends one configurable webhook after a deployment, manually or via your deployment pipeline with `./craft deployments/notify`.
- Automatically hooks Craft Cloud's `cloud/up` after-up event when `craftcms/cloud`
  is installed, so Cloud deploys trigger webhook automatically.
- Webhook URL, HTTP method (POST/GET), body, and request headers are configurable
  in Settings → Plugins → Deployments.
- The URL and header values are Twig templates and resolve environment variables;
  the body is a Twig template with `DEPLOY_ENVIRONMENT`, `DEPLOY_SHA`,
  `DEPLOY_CRAFT_VERSION`, `DEPLOY_SITE_NAME`, `DEPLOY_SITE_URL`,
  `DEPLOY_TIMESTAMP`, `DEPLOY_TIME`, and `DEPLOY_HOSTNAME` in scope.
  `DEPLOY_TIMESTAMP` is ISO 8601 in UTC; `DEPLOY_TIME` is the same instant in the
  site's configured timezone, formatted `Y-m-d g:ia T`.
- Bodies that render to JSON get a `Content-Type: application/json` header
  automatically; anything else gets `text/plain; charset=UTF-8`. An explicit
  `Content-Type` header overrides detection.
- `--dry-run` previews the fully rendered request without sending it, with header
  values redacted.
- Delivery retries 3 times with backoff and never fails a deploy: failures are
  logged under the `deployments` category and the command still exits 0.

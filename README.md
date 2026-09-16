# Deployments

Send one configurable webhook after a Craft CMS deployment to platforms like Slack, a CI
system, an uptime monitor, or a Grafana annotation endpoint without every
project hand-rolling its own console command.

Fire it explicitly with `./craft deployments/notify`, or let it fire itself
after Craft Cloud's `cloud/up`.

## Requirements

- Craft CMS 5
- PHP 8.2+

## Installation

```bash
composer require sunnybyte/craft-deployments
./craft plugin/install deployments
```

## Settings

Settings → Plugins → Deployments.

| Setting | Notes |
| --- | --- |
| **Webhook URL** | Where to send the notification. Rendered as a Twig template and then resolved through `App::parseEnv()`, so both `{{ DEPLOY_SHA }}` and `$MY_WEBHOOK_URL` work. **Leave blank to disable the plugin.** |
| **Method** | `POST` (default) or `GET`. |
| **Body** | Twig template for the request body. Ignored for `GET`. |
| **Headers** | Any extra request headers. Values are rendered as Twig *and* resolve environment variables, so `Bearer ${DEPLOY_TOKEN}` and `{{ DEPLOY_SHA }}` both work — see [Environment variables](#environment-variables). |

There is no separate on/off toggle: a blank webhook URL is the off switch.

## Environment variables

The webhook URL and header values are resolved with Craft's `App::parseEnv()`,
which supports two syntaxes — and the difference matters:

| Value | Result |
| --- | --- |
| `$DEPLOY_TOKEN` | ✅ `s3cret` — a bare `$VAR` resolves only when it is the **entire** value |
| `Bearer ${DEPLOY_TOKEN}` | ✅ `Bearer s3cret` — use braces to interpolate mid-string |
| `Bearer $DEPLOY_TOKEN` | ❌ sent literally as `Bearer $DEPLOY_TOKEN` |

So for the usual bearer-token header, write `Bearer ${DEPLOY_TOKEN}`, not
`Bearer $DEPLOY_TOKEN`. Use `--dry-run` if you're unsure — though note that it
redacts header values, so send to a local listener to see what actually goes on
the wire.

## Variables

Available in the URL template, the body template, and every header value:

| Variable | Value                                                                                                    |
| --- |----------------------------------------------------------------------------------------------------------|
| `DEPLOY_ENVIRONMENT` | `Craft::$app->env` e.g. `production`                                                                     |
| `DEPLOY_SHA` | The deployed commit (see below)                                                                          |
| `DEPLOY_CRAFT_VERSION` | e.g. `5.11.1`                                                                                            |
| `DEPLOY_SITE_NAME` | The primary site's name                                                                                  |
| `DEPLOY_SITE_URL` | The primary site's base URL                                                                              |
| `DEPLOY_TIMESTAMP` | ISO 8601, UTC - e.g. `2026-09-15T18:04:11+00:00`                                                         |
| `DEPLOY_TIME` | The same instant in the site's configured timezone, formatted for humans - e.g. `2026-09-15 11:04am PDT` |
| `DEPLOY_HOSTNAME` | The machine hostname, or `unknown`                                                                       |

Every value is a string. Values that can't be determined are empty strings, never
`null`, so `{{ DEPLOY_SHA }}` never renders the word "null" and `|json_encode`
always produces a JSON string.

**About `DEPLOY_SHA`:** it comes from Composer's root package reference, which is
`null` whenever the project wasn't installed from a git checkout. If your
pipeline knows the commit, export `DEPLOY_SHA` and it wins:

```bash
DEPLOY_SHA=$(git rev-parse HEAD) ./craft deployments/notify
```

## Example: Slack

Method `POST`, body:

```twig
{
  "text": "Deployed {{ DEPLOY_SHA|slice(0, 7) }} to {{ DEPLOY_ENVIRONMENT }} - Craft {{ DEPLOY_CRAFT_VERSION }}",
  "username": {{ DEPLOY_SITE_NAME|json_encode|raw }}
}
```

That renders to JSON, so `Content-Type: application/json` is set automatically -
see [Content types](#content-types).

## Example: GET ping

Method `GET`, webhook URL:

```
https://ci.example.com/deploy-hook?env={{ DEPLOY_ENVIRONMENT }}&sha={{ DEPLOY_SHA }}
```

No body is sent for `GET`, so all the deploy data goes in the query string.

## Content types

The rendered body is inspected:

- Starts with `{` or `[` **and** parses as JSON → `application/json`
- Anything else non-empty → `text/plain; charset=UTF-8`
- Empty → no `Content-Type` header

A `Content-Type` row in the Headers table always overrides this.

## Triggers

### Any host

Run it as the last step of your deploy:

```bash
./craft deployments/notify
```

Preview the fully rendered request without sending anything:

```bash
./craft deployments/notify --dry-run
```

Dry-run output redacts header values, since it tends to end up in CI logs.
`Content-Type`, `Accept`, `User-Agent`, and any `X-Deploy-*` header are shown in
full.

### Craft Cloud

If `craftcms/cloud` is installed, the plugin hooks `cloud/up`'s after-up event
automatically. **No pipeline changes are needed** - deploy as usual and the
webhook fires once `cloud/up` finishes.

### Pick one trigger

Running *both* `cloud/up` and `./craft deployments/notify` in the same pipeline
sends **two** webhooks. They're separate PHP processes, so the plugin can't
deduplicate across them. Choose one.

## Failures never fail a deploy

Delivery is attempted 3 times with 1s then 2s of backoff. Error statuses (4xx,
5xx) are retried too, since a receiver restarting behind a proxy returns 502 for
a few seconds and then works.

If it still fails, a warning is logged under the `deployments` category and
that's it:

- `./craft deployments/notify` **always exits 0**
- the `cloud/up` after-up event is **never** cancelled

A deploy that has already migrated the database and warmed caches is a successful
deploy, whatever your webhook receiver thinks. A broken Slack webhook integration should
not be able to take down a release.

The same applies to broken templates: if the URL, body, or a header value fails
to render, nothing is sent at all. A payload missing its SHA, or a request
missing its `Authorization` header, is worse than no request and the failure is
logged with the name of the template that broke.

## License

GPL-3.0-or-later

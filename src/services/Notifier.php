<?php

namespace sunnybyte\deployments\services;

use Composer\InstalledVersions;
use Craft;
use craft\helpers\App;
use craft\web\View;
use sunnybyte\deployments\models\Settings;

/**
 * Assembles, renders, and delivers the deployment webhook.
 *
 * Everything the plugin actually does lives here; Plugin and NotifyController
 * are thin wrappers. Split into small methods so the interesting parts —
 * variable assembly, rendering, Content-Type detection, header resolution — can
 * be reasoned about and exercised separately.
 */
class Notifier
{
    /** Delivery attempts before giving up. */
    private const ATTEMPTS = 3;

    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 10;

    /** Header names whose values are safe to print in --dry-run output. */
    private const REDACT_ALLOWLIST = [
        'content-type',
        'accept',
        'user-agent',
    ];

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * Whether the plugin is configured enough to send anything. A blank webhook
     * URL is the plugin's off switch.
     */
    public function isConfigured(): bool
    {
        return trim($this->settings->webhookUrl) !== '';
    }

    /**
     * The HTTP method, normalized. Anything unexpected falls back to POST
     * rather than being sent as-is.
     */
    public function method(): string
    {
        $method = strtoupper(trim($this->settings->method));

        return $method === 'GET' ? 'GET' : 'POST';
    }

    /**
     * Deploy metadata exposed to the URL and body templates.
     *
     * Every value is a string, and unavailable values are '' rather than null,
     * so "{{ DEPLOY_SHA }}" never renders the word "null" and |json_encode
     * always produces a JSON string.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $site = Craft::$app->getSites()->getPrimarySite();

        // One instant for both time variables, so a deploy that straddles a
        // second boundary can't report two different times.
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'DEPLOY_ENVIRONMENT' => (string)Craft::$app->env,
            'DEPLOY_SHA' => $this->sha(),
            'DEPLOY_CRAFT_VERSION' => Craft::$app->getVersion(),
            'DEPLOY_SITE_NAME' => (string)$site->getName(),
            'DEPLOY_SITE_URL' => (string)($site->getBaseUrl() ?? ''),
            'DEPLOY_TIMESTAMP' => $now->format(\DateTimeInterface::ATOM),
            'DEPLOY_TIME' => $this->localTime($now),
            'DEPLOY_HOSTNAME' => $this->hostname(),
        ];
    }

    /**
     * The rendered, env-resolved target URL.
     *
     * @throws RenderException
     */
    public function renderUrl(): string
    {
        $url = $this->render($this->settings->webhookUrl, 'webhook URL');
        $url = App::parseEnv($url);

        return is_string($url) ? trim($url) : '';
    }

    /**
     * The rendered request body. Not env-resolved: a body is content, not
     * configuration.
     *
     * @throws RenderException
     */
    public function renderBody(): string
    {
        return $this->render($this->settings->body, 'body template');
    }

    /**
     * The Content-Type implied by a rendered body, or null when there's nothing
     * to type.
     *
     * The prefix check is not redundant with json_decode(): json_decode('123')
     * and json_decode('"x"') both succeed, and labelling a plain-text body of
     * "123" as application/json would be wrong.
     */
    public function detectContentType(string $body): ?string
    {
        $body = trim($body);

        if ($body === '') {
            return null;
        }

        if (str_starts_with($body, '{') || str_starts_with($body, '[')) {
            json_decode($body);

            if (json_last_error() === JSON_ERROR_NONE) {
                return 'application/json';
            }
        }

        return 'text/plain; charset=UTF-8';
    }

    /**
     * The Content-Type to add for this body, or null if one shouldn't be added
     * — either because the body is empty, or because the user set their own
     * Content-Type header, which always wins.
     *
     * @param array<string, string> $headers
     */
    public function contentTypeFor(string $body, array $headers): ?string
    {
        foreach (array_keys($headers) as $name) {
            if (strtolower((string)$name) === 'content-type') {
                return null;
            }
        }

        return $this->detectContentType($body);
    }

    /**
     * Configured headers with their values rendered as Twig and then
     * env-resolved, so "Bearer ${DEPLOY_TOKEN}" and "{{ DEPLOY_SHA }}" both work.
     *
     * Note that App::parseEnv() only expands a bare "$VAR" when it is the whole
     * value; mid-string references need the braced "${VAR}" form.
     *
     * @return array<string, string>
     * @throws RenderException
     */
    public function resolveHeaders(): array
    {
        $headers = [];

        foreach ($this->settings->headerRows() as $row) {
            $name = $row['name'];
            $value = $this->render($row['value'], sprintf('“%s” header', $name));
            $value = App::parseEnv($value);

            $headers[$name] = is_string($value) ? trim($value) : '';
        }

        return $headers;
    }

    /**
     * Header values masked for display. --dry-run output routinely ends up in
     * CI logs, and the most common custom header is a bearer token, so values
     * are hidden unless the name is obviously non-secret.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function redactHeaders(array $headers): array
    {
        $display = [];

        foreach ($headers as $name => $value) {
            $key = strtolower((string)$name);
            $safe = in_array($key, self::REDACT_ALLOWLIST, true)
                || str_starts_with($key, 'x-deploy-');

            $display[$name] = $safe ? $value : '<redacted>';
        }

        return $display;
    }

    /**
     * Everything that would be sent, fully rendered. Shared by notify() and the
     * --dry-run output so the preview can't drift from the real request.
     *
     * @return array{method: string, url: string, headers: array<string, string>, contentType: string|null, body: string}
     * @throws RenderException
     */
    public function describe(): array
    {
        $method = $this->method();
        $headers = $this->resolveHeaders();
        $body = $method === 'POST' ? $this->renderBody() : '';
        $contentType = $method === 'POST' ? $this->contentTypeFor($body, $headers) : null;

        return [
            'method' => $method,
            'url' => $this->renderUrl(),
            'headers' => $headers,
            'contentType' => $contentType,
            'body' => $body,
        ];
    }

    /**
     * Send the webhook. Returns true on success.
     *
     * Never throws and never fails a deploy: a render failure, an unreachable
     * host, or an error status all end in a logged warning and a false return.
     * A deploy that has already migrated the database and warmed caches is a
     * successful deploy, whatever Slack thinks.
     */
    public function notify(): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $request = $this->describe();
        } catch (RenderException $e) {
            Craft::warning($e->getMessage(), 'deployments');

            return false;
        }

        if ($request['url'] === '') {
            Craft::warning('The deployment webhook URL rendered empty; nothing sent.', 'deployments');

            return false;
        }

        $headers = $request['headers'];

        if ($request['contentType'] !== null) {
            $headers['Content-Type'] = $request['contentType'];
        }

        $options = [
            'headers' => $headers,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => self::TIMEOUT,
            // Retry error statuses too: a receiver restarting behind a proxy
            // returns 502 for a few seconds and then works.
            'http_errors' => true,
        ];

        if ($request['method'] === 'POST') {
            $options['body'] = $request['body'];
        }

        $client = Craft::createGuzzleClient();
        $lastError = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $client->request($request['method'], $request['url'], $options);

                return true;
            } catch (\Throwable $e) {
                $lastError = $e;

                if ($attempt < self::ATTEMPTS) {
                    sleep($attempt); // linear backoff: 1s, then 2s
                }
            }
        }

        Craft::warning(sprintf(
            'Deployment webhook to %s failed after %d attempts: %s',
            $request['url'],
            self::ATTEMPTS,
            $lastError?->getMessage() ?? 'unknown error',
        ), 'deployments');

        return false;
    }

    /**
     * Render one user-authored template with the deploy variables.
     *
     * Site template mode is passed explicitly because console requests default
     * to CP mode, and a setting should behave identically however it was
     * triggered. escapeHtml stays false (View::renderString's default) so JSON
     * payloads aren't mangled into &quot; entities.
     *
     * @throws RenderException
     */
    private function render(string $template, string $label): string
    {
        if (trim($template) === '') {
            return '';
        }

        try {
            return Craft::$app->getView()->renderString(
                $template,
                $this->variables(),
                View::TEMPLATE_MODE_SITE,
            );
        } catch (\Throwable $e) {
            throw new RenderException(
                sprintf('Failed to render the deployment %s: %s', $label, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * The deployed commit.
     *
     * $DEPLOY_SHA wins when set, because Composer's root package reference is
     * null whenever the project wasn't installed from a git checkout, and a
     * pipeline that knows the SHA should be able to say so.
     */
    private function sha(): string
    {
        $env = App::env('DEPLOY_SHA');

        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        try {
            $reference = InstalledVersions::getRootPackage()['reference'] ?? null;
        } catch (\Throwable) {
            $reference = null;
        }

        return is_string($reference) ? $reference : '';
    }

    /**
     * The deploy time in the site's own timezone, as "2026-09-16 10:03am PDT".
     *
     * Craft's configured timezone rather than UTC, because this one is meant to
     * be read by a human in a Slack message — DEPLOY_TIMESTAMP is the machine
     * -readable counterpart. Falls back to the given (UTC) time if the
     * configured zone is somehow unusable.
     */
    private function localTime(\DateTimeImmutable $now): string
    {
        try {
            $now = $now->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()));
        } catch (\Throwable) {
            // Keep UTC rather than losing the value entirely.
        }

        return $now->format('Y-m-d g:ia T');
    }

    private function hostname(): string
    {
        $host = gethostname();

        return ($host !== false && $host !== '') ? $host : 'unknown';
    }
}

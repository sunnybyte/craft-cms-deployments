<?php

namespace sunnybyte\deployments\console\controllers;

use craft\console\Controller;
use sunnybyte\deployments\Plugin;
use sunnybyte\deployments\services\RenderException;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Sends the deployment webhook.
 *
 *   ./craft deployments/notify
 *   ./craft deployments/notify --dry-run
 *
 * Run this as the last step of a deploy. On Craft Cloud you don't need to:
 * the plugin hooks `cloud/up` automatically.
 *
 * Always exits 0. A webhook is a notification about a deploy, not part of it,
 * so it must never be able to fail one.
 */
class NotifyController extends Controller
{
    /** Print what would be sent, without sending it. */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun']);
    }

    public function actionIndex(): int
    {
        $notifier = Plugin::getInstance()->getNotifier();

        if (!$notifier->isConfigured()) {
            $this->stdout(
                "No webhook URL is set, so there's nothing to send.\n" .
                "Set one in Settings -> Plugins -> Deployments.\n",
                Console::FG_YELLOW,
            );

            // Not an error: an unconfigured plugin on a dev box shouldn't be noisy.
            return ExitCode::OK;
        }

        try {
            $request = $notifier->describe();
        } catch (RenderException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            $this->stdout("Nothing was sent.\n");

            return ExitCode::OK;
        }

        $this->stdout("Method:       {$request['method']}\n");
        $this->stdout("URL:          {$request['url']}\n");

        foreach ($notifier->redactHeaders($request['headers']) as $name => $value) {
            $this->stdout(sprintf("Header:       %s: %s\n", $name, $value));
        }

        if ($request['method'] === 'POST') {
            $this->stdout('Content-Type: ' . ($request['contentType'] ?? '(none)') . "\n");
            $this->stdout("Body:\n{$request['body']}\n");
        }

        if ($this->dryRun) {
            $this->stdout("\nDry run: nothing sent.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout("\nSending...\n");

        if ($notifier->notify()) {
            $this->stdout("Webhook delivered.\n", Console::FG_GREEN);
        } else {
            // notify() has already logged the details under the "deployments"
            // category. Still exit 0 — see the class docblock.
            $this->stderr("Webhook failed. See the Craft logs for details.\n", Console::FG_RED);
        }

        return ExitCode::OK;
    }
}

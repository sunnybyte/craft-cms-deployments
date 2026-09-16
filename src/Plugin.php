<?php

namespace sunnybyte\deployments;

use Composer\InstalledVersions;
use Craft;
use craft\base\Model;
use craft\cloud\cli\controllers\UpController;
use sunnybyte\deployments\models\Settings;
use sunnybyte\deployments\services\Notifier;
use yii\base\Event;

/**
 * Deployments plugin.
 *
 * Sends a single configurable webhook after a deployment, so a deploy can
 * announce itself to Slack, a CI system, or an annotation endpoint without each
 * project hand-rolling its own console command.
 *
 * Fired explicitly with `./craft deployments/notify`, or automatically after
 * Craft Cloud's `cloud/up` when craftcms/cloud is installed.
 *
 * Configured via Settings -> Plugins -> Deployments. A blank webhook URL
 * disables the plugin.
 *
 * @method Settings getSettings()
 * @method static Plugin getInstance()
 */
class Plugin extends \craft\base\Plugin
{
    private const PACKAGE = 'sunnybyte/craft-deployments';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = true;

    /** Whether this process has already sent its notification. */
    private static bool $notified = false;

    private ?Notifier $notifier = null;

    public function init(): void
    {
        parent::init();

        $this->registerCloudHook();
    }

    /**
     * The notifier, built from the current settings. Lazily constructed so
     * installing the plugin without configuring it costs nothing.
     */
    public function getNotifier(): Notifier
    {
        return $this->notifier ??= new Notifier($this->getSettings());
    }

    /**
     * Send the webhook at most once per process, swallowing anything that goes
     * wrong. The guard exists so an unexpected second EVENT_AFTER_UP can't
     * double-post.
     *
     * Note that `cloud/up` and `./craft deployments/notify` are separate
     * processes, so running both in one pipeline does send two webhooks. Pick
     * one trigger.
     */
    public function notifyOnce(): bool
    {
        if (self::$notified) {
            return true;
        }

        self::$notified = true;

        try {
            return $this->getNotifier()->notify();
        } catch (\Throwable $e) {
            Craft::warning('Deployment webhook failed: ' . $e->getMessage(), 'deployments');

            return false;
        }
    }

    /**
     * Exactly which code is running — e.g. "1.0.0 (commit 1686c9d)", or
     * "commit 1686c9d (dev-main)" if no version has been declared.
     *
     * The commit is the part the Plugins screen can't show: Craft reads that
     * version string from vendor/craftcms/plugins.php, which
     * craftcms/plugin-installer writes once at composer-install time, so it
     * can't reflect the specific commit a branch install is sitting on.
     * Composer's runtime data still knows it, so we surface it here.
     */
    public function installedVersion(): ?string
    {
        // Craft's own view of the version, so this line always agrees with the
        // Plugins screen. It comes from extra.version in composer.json, falling
        // back to Composer's package version ("dev-main" for a branch install).
        $version = $this->getVersion();

        try {
            $reference = InstalledVersions::getReference(self::PACKAGE);
        } catch (\Throwable) {
            // Not Composer-installed under that name (e.g. loaded as a path
            // module while developing the plugin itself).
            $reference = null;
        }

        $commit = ($reference !== null && $reference !== '')
            ? substr($reference, 0, 7)
            : null;

        if ($commit === null) {
            return $version !== '' ? $version : null;
        }

        if ($version === '') {
            return "commit $commit";
        }

        // A branch install's "dev-main" says nothing useful, so lead with the
        // commit. A tagged release's number is the headline, so lead with that.
        return (str_starts_with($version, 'dev-') || str_ends_with($version, '-dev'))
            ? "commit $commit ($version)"
            : "$version (commit $commit)";
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('deployments/settings', [
            'settings' => $this->getSettings(),
            'installedVersion' => $this->installedVersion(),
        ]);
    }

    /**
     * Fire the webhook after Craft Cloud's `cloud/up`, so a Cloud deploy needs
     * no pipeline changes at all.
     *
     * Registered at class level: UpController triggers the event on itself, and
     * Yii's Component::trigger() dispatches to class-level handlers, so no
     * instance is needed. Guarded by class_exists() so nothing is registered on
     * non-Cloud sites, and restricted to console requests because that's the
     * only place cloud/up runs.
     */
    private function registerCloudHook(): void
    {
        if (!Craft::$app instanceof \craft\console\Application) {
            return;
        }

        if (!class_exists(UpController::class)) {
            return;
        }

        Event::on(
            UpController::class,
            UpController::EVENT_AFTER_UP,
            function(): void {
                // Deliberately ignores the CancelableEvent's isValid flag:
                // invalidating it would make cloud/up exit non-zero and fail
                // the build over a failed notification.
                $this->notifyOnce();
            },
        );
    }
}

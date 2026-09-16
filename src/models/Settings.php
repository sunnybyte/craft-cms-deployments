<?php

namespace sunnybyte\deployments\models;

use Craft;
use craft\base\Model;

/**
 * Deployments settings.
 *
 * The webhook URL and every header value are Twig templates rendered with the
 * DEPLOY_* variables (see services\Notifier::variables()), then resolved through
 * App::parseEnv() so "$MY_TOKEN" style references expand. A blank webhook URL
 * disables the plugin entirely — there is no separate on/off toggle.
 */
class Settings extends Model
{
    /** Target URL, as a Twig template. Blank disables the plugin. */
    public string $webhookUrl = '';

    /** HTTP method: 'POST' or 'GET'. */
    public string $method = 'POST';

    /** Twig template for the request body. Ignored when $method is 'GET'. */
    public string $body = '';

    /** Rows of ['name' => string, 'value' => string]. */
    public array $headers = [];

    public function rules(): array
    {
        return [
            [['webhookUrl', 'method', 'body'], 'trim'],
            [['webhookUrl', 'method', 'body'], 'string'],
            ['method', 'in', 'range' => ['POST', 'GET']],
            ['headers', 'validateHeaders'],
        ];
    }

    /**
     * Rejects duplicate header names. Blank names aren't an error — an empty
     * row is just an unfilled row in the editable table, and headerRows()
     * drops it.
     */
    public function validateHeaders(string $attribute): void
    {
        $seen = [];

        foreach ($this->headerRows() as $row) {
            $key = strtolower($row['name']);

            if (isset($seen[$key])) {
                $this->addError($attribute, Craft::t('deployments', 'Duplicate header “{name}”.', [
                    'name' => $row['name'],
                ]));

                return;
            }

            $seen[$key] = true;
        }
    }

    /**
     * Headers as a normalized list, with blank names dropped and names/values
     * trimmed. Craft's editableTableField posts rows keyed by row id, so this
     * also flattens that back into a list.
     *
     * @return array<int, array{name: string, value: string}>
     */
    public function headerRows(): array
    {
        $rows = [];

        foreach ($this->headers as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = trim((string)($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'value' => trim((string)($row['value'] ?? '')),
            ];
        }

        return $rows;
    }
}

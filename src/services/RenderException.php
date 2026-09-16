<?php

namespace sunnybyte\deployments\services;

/**
 * Thrown when one of the user-authored Twig templates (the URL, the body, or a
 * header value) fails to render.
 *
 * Rendering failures abort the whole notification rather than sending a
 * partially-rendered request: a payload missing its SHA, or a request missing
 * its Authorization header, is worse than no request at all.
 */
class RenderException extends \RuntimeException
{
}

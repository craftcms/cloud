<?php

namespace craft\cloud\ops;

use Craft;
use craft\helpers\UrlHelper;
use InvalidArgumentException;

class Esi
{
    public function __construct(
        private readonly UrlSigner $urlSigner,
        private readonly bool $useEsi = true,
    ) {
    }

    /**
     * Prepare response for ESI processing by setting the Surrogate-Control header
     * Note: The Surrogate-Control header will cause Cloudflare to ignore
     * the Cache-Control header: https://developers.cloudflare.com/cache/concepts/cdn-cache-control/#header-precedence
     */
    public function prepareResponse(): void
    {
        Craft::$app->getResponse()->getHeaders()->setDefault(
            HeaderEnum::SURROGATE_CONTROL->value,
            'content="ESI/1.0"',
        );
    }

    public function render(string $template, array $variables = []): string
    {
        $this->validateVariables($variables);

        if (!$this->useEsi) {
            return Craft::$app->getView()->renderTemplate($template, $variables);
        }

        $this->prepareResponse();

        $url = UrlHelper::actionUrl('cloud/esi/render-template', [
            'template' => $template,
            'variables' => $variables,
        ]);

        $signedUrl = $this->urlSigner->sign($url);

        return Craft::$app->getView()->renderString(
            '<esi:include src="{{ src }}" />',
            [
                'src' => $signedUrl,
            ]
        );
    }

    private function validateVariables(array $variables): void
    {
        foreach ($variables as $value) {
            if (is_array($value)) {
                $this->validateVariables($value);
            } elseif (!is_scalar($value) && !is_null($value)) {
                $type = get_debug_type($value);

                throw new InvalidArgumentException(
                    "Value must be a primitive value or array, {$type} given."
                );
            }
        }
    }
}

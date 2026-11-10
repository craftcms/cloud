<?php

declare(strict_types=1);

namespace craft\cloud\transforms;

use CraftCms\Cms\Asset\AssetsHelper;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cms;
use GuzzleHttp\Psr7\HttpFactory;
use HttpMessageSignatures\Algorithm\HmacSha256;
use HttpMessageSignatures\Url\UrlSigner;
use HttpMessageSignatures\Url\UrlSigningConfig;
use League\Uri\Components\Query;
use League\Uri\Modifier;

/**
 * Signed transform URLs on Craft Cloud’s asset CDN, which its gateway verifies before transforming.
 */
class AssetCdn
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $signingKey,
    ) {}

    /**
     * The asset’s URL with Cloudflare image options in its query, signed over `@path` and `@query` in the `s`
     * parameter. The gateway leaves `v` out of the signature, so the asset’s revision is added afterward, like on
     * other asset URLs.
     *
     * @param array<string, mixed> $options
     */
    public function transformUrl(Asset $asset, array $options): string
    {
        $url = Modifier::wrap(AssetsHelper::generateUrl($asset))
            ->removeQueryParameters('v')
            ->mergeQuery(Query::fromVariable($options))
            ->removeEmptyQueryPairs()
            ->toString();

        $url = new UrlSigner(
            algorithm: new HmacSha256($this->signingKey),
            requestFactory: new HttpFactory(),
            config: new UrlSigningConfig(components: ['@path', '@query'], signatureParam: 's'),
        )->sign($url);

        if (!Cms::config()->revAssetUrls) {
            return $url;
        }

        return Modifier::wrap($url)->appendQueryParameters(AssetsHelper::revParams($asset))->toString();
    }
}

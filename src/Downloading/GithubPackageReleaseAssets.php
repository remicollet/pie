<?php

declare(strict_types=1);

namespace Php\Pie\Downloading;

use Composer\Downloader\TransportException;
use Composer\Util\AuthHelper;
use Composer\Util\HttpDownloader;
use Php\Pie\DependencyResolver\Package;
use Php\Pie\Platform\TargetPlatform;
use Php\Pie\Platform\WindowsExtensionAssetName;
use Webmozart\Assert\Assert;

use function array_map;
use function in_array;
use function strtolower;

/** @internal This is not public API for PIE, so should not be depended upon unless you accept the risk of BC breaks */
final class GithubPackageReleaseAssets implements PackageReleaseAssets
{
    /** @psalm-api */
    public function __construct(
        private readonly string $githubApiBaseUrl,
    ) {
    }

    /** @return non-empty-string */
    public function findWindowsDownloadUrlForPackage(
        TargetPlatform $targetPlatform,
        Package $package,
        AuthHelper $authHelper,
        HttpDownloader $httpDownloader,
    ): string {
        $releaseAsset = $this->selectMatchingReleaseAsset(
            $targetPlatform,
            $package,
            $this->getReleaseAssetsForPackage($package, $authHelper, $httpDownloader),
        );

        return $releaseAsset['browser_download_url'];
    }

    /** @return non-empty-list<non-empty-string> */
    private function expectedWindowsAssetNames(TargetPlatform $targetPlatform, Package $package): array
    {
        return WindowsExtensionAssetName::zipNames($targetPlatform, $package);
    }

    /** @link https://github.com/squizlabs/PHP_CodeSniffer/issues/3734 */
    // phpcs:disable Squiz.Commenting.FunctionComment.MissingParamName
    /**
     * @param list<array{name: non-empty-string, browser_download_url: non-empty-string, ...}> $releaseAssets
     *
     * @return array{name: non-empty-string, browser_download_url: non-empty-string, ...}
     */
    // phpcs:enable
    private function selectMatchingReleaseAsset(TargetPlatform $targetPlatform, Package $package, array $releaseAssets): array
    {
        $expectedAssetNames = $this->expectedWindowsAssetNames($targetPlatform, $package);

        foreach ($releaseAssets as $releaseAsset) {
            if (in_array(strtolower($releaseAsset['name']), $expectedAssetNames, true)) {
                return $releaseAsset;
            }
        }

        throw Exception\CouldNotFindReleaseAsset::forPackage($package, $expectedAssetNames);
    }

    /** @return list<array{name: non-empty-string, browser_download_url: non-empty-string, ...}> */
    private function getReleaseAssetsForPackage(
        Package $package,
        AuthHelper $authHelper,
        HttpDownloader $httpDownloader,
    ): array {
        Assert::notNull($package->downloadUrl);

        try {
            $decodedRepsonse = $httpDownloader->get(
                $this->githubApiBaseUrl . '/repos/' . $package->githubOrgAndRepository() . '/releases/tags/' . $package->version,
                [
                    'retry-auth-failure' => false,
                    'http' => [
                        'method' => 'GET',
                        'header' => $authHelper->addAuthenticationHeader([], $this->githubApiBaseUrl, $package->downloadUrl),
                    ],
                ],
            )->decodeJson();
        } catch (TransportException $t) {
            /** @link https://docs.github.com/en/rest/releases/releases?apiVersion=2022-11-28#get-a-release-by-tag-name */
            if ($t->getStatusCode() === 404) {
                throw Exception\CouldNotFindReleaseAsset::forPackageWithMissingTag($package);
            }

            throw $t;
        }

        Assert::isArray($decodedRepsonse);
        Assert::keyExists($decodedRepsonse, 'assets');
        Assert::isList($decodedRepsonse['assets']);

        return array_map(
            static function (array $asset): array {
                Assert::keyExists($asset, 'name');
                Assert::stringNotEmpty($asset['name']);
                Assert::keyExists($asset, 'browser_download_url');
                Assert::stringNotEmpty($asset['browser_download_url']);

                return $asset;
            },
            $decodedRepsonse['assets'],
        );
    }
}

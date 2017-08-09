<?php

namespace ShyimPluginManager\Bundle\ComposerBundle\Service;

use Doctrine\DBAL\Connection;
use ShyimPluginManager\Bundle\ComposerBundle\Struct\Plugin;
use Shopware\Components\HttpClient\HttpClientInterface;
use Zend_Cache_Core;

class PluginListService
{
    /**
     * @var HttpClientInterface
     */
    private $httpClient;
    /**
     * @var Zend_Cache_Core
     */
    private $cache;
    /**
     * @var array
     */
    private $shopwarePluginTypes = [
        'shopware-plugin',
        'shopware-core-plugin',
        'shopware-frontend-plugin',
        'shopware-backend-plugin',
    ];
    /**
     * @var array
     */
    private $installedPlugins = [];
    /**
     * @var Connection
     */
    private $connection;
    /**
     * @var array
     */
    private $blacklist = [
        'shopec'
    ];

    /**
     * PluginListService constructor.
     *
     * @param HttpClientInterface $httpClient
     * @param Zend_Cache_Core $cache
     * @param Connection $connection
     */
    public function __construct(
        HttpClientInterface $httpClient,
        Zend_Cache_Core $cache,
        Connection $connection
    )
    {
        $this->httpClient = $httpClient;
        $this->cache = $cache;
        $this->connection = $connection;
    }

    /**
     * @return array
     */
    public function getPluginList()
    {
        $plugins = [];
        $this->installedPlugins = $this->connection->fetchAll('SELECT * FROM s_core_plugins WHERE active = 1 OR installation_date != NULL');
        foreach ($this->shopwarePluginTypes as $shopwarePluginType) {
            $this->fetchPluginsByComposerType($shopwarePluginType, $plugins);
        }
        return $plugins;
    }

    /**
     * Parses composer packages from packagist
     *
     * @param string $composerType
     * @param array $plugins
     */
    private function fetchPluginsByComposerType($composerType, &$plugins)
    {
        $request = $this->httpClient->get('https://packagist.org/packages/list.json?type=' . $composerType);
        $body = json_decode($request->getBody(), true);
        // get addional package info
        foreach ($body['packageNames'] as &$composerPackage) {
            if ($this->isBlacklistedPackage($composerPackage)) {
                continue;
            }
            $composerPackageRequest = $this->httpClient->get('https://packagist.org/p/' . $composerPackage . '.json');
            $composerPackageBody = json_decode($composerPackageRequest->getBody(), true);
            $composerPackageBody = array_reverse($composerPackageBody['packages'][$composerPackage]);
            $latestVersion = $this->getLatestVersion($composerPackageBody);
            // Missing installer-name in composer.json
            if (empty($latestVersion['extra']['installer-name'])) {
                continue;
            }
            $plugin = new Plugin();
            foreach ($this->installedPlugins as $installedPlugin) {
                if ($installedPlugin['name'] == $latestVersion['extra']['installer-name']) {
                    $plugin->setState(($installedPlugin['active'] ? 2 : 1));
                    $plugin->setCurrentVersion($installedPlugin['version']);
                }
            }
            $plugin->setName($composerPackage);
            $plugin->setType($latestVersion['type']);
            $plugin->setTime($latestVersion['time']);
            $plugin->setVersion($latestVersion['version']);
            $plugin->setDescription($latestVersion['description']);
            $plugin->setDownloads(0); //TODO: get with new method, before: $composerPackage['downloads']
            $plugin->setFavers(0); //TODO: get with new method, before: $composerPackage['favers']
            $plugin->setAuthors($latestVersion['authors']);
            $plugin->setHomepage($latestVersion['homepage']);
            $plugin->setInstallName($latestVersion['extra']['installer-name']);
            $plugin->setLicense($latestVersion['license']);
            $plugin->setKeywords($latestVersion['keywords']);
            $plugin->setUrl(''); //TODO: get with new method, before: $composerPackage['url']
            $plugin->setRepository(''); //TODO: get with new method, before: $composerPackage['repository']
            $plugins[] = $plugin;
        }
    }

    /**
     * @param array $versions
     * @return array
     */
    private function getLatestVersion($versions)
    {
        $latestVersion = [];
        foreach ($versions as $item) {
            if (strpos($item['version'], 'dev') === false) {
                $latestVersion = $item;
                break;
            }
        }
        // fallback to dev-master
        if (empty($latestVersion)) {
            $latestVersion = current($versions);
        }
        return $latestVersion;
    }

    /**
     * @param string $name
     * @return bool
     */
    private function isBlacklistedPackage($name)
    {
        foreach ($this->blacklist as $blacklist) {
            if (strpos($name, $blacklist) !== false) {
                return true;
            }
        }
        return false;
    }
}
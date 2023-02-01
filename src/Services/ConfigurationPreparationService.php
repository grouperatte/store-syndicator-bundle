<?php

namespace TorqIT\StoreSyndicatorBundle\Services;

use Pimcore\Bundle\DataHubBundle\Configuration\Dao;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ConfigurationPreparationService
{
    /**
     * @param string $configName
     * @param string|array|null $currentConfig
     * @param bool $ignorePermissions
     *
     * @return array
     *
     * @throws \Exception
     */
    public function prepareConfiguration(string $configName, $currentConfig = null, $ignorePermissions = false)
    {
        if ($currentConfig) {
            if (is_string($currentConfig)) {
                $currentConfig = json_decode($currentConfig, true);
            }
            $config = $currentConfig;
        } else {
            $configuration = Dao::getByName($configName);
            if (!$configuration) {
                throw new \Exception('Configuration ' . $configName . ' does not exist.');
            }

            $config = $configuration->getConfiguration();
            if (!$ignorePermissions) {
                if (!$configuration->isAllowed('read')) {
                    throw new AccessDeniedHttpException('Access denied');
                }

                $config['userPermissions'] = [
                    'update' => $configuration->isAllowed('update'),
                    'delete' => $configuration->isAllowed('delete')
                ];
            }
        }

        //init config array with default values
        $config = array_merge([
            'APIAccess' => [],
            'attributeMap' => [],
            'products' => [],
        ], $config);

        return $config;
    }
}

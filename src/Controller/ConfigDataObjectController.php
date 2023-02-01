<?php

namespace TorqIT\StoreSyndicatorBundle\Controller;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Pimcore\Bundle\DataHubBundle\Configuration\Dao;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use TorqIT\StoreSyndicatorBundle\Services\ConfigurationPreparationService;

/**
 * @Route("/admin/storesyndicator/dataobject/config", name="pimcore_storesyndicator_configdataobject")
 */
class ConfigDataObjectController extends FrontendController
{
    /**
     * @Route("/get", name="_get")
     *
     * @param Request $request
     * @param ConfigurationPreparationService $configurationPreparationService
     * @param InterpreterFactory $interpreterFactory
     *
     * @return JsonResponse
     *
     * @throws \Exception
     */
    public function getAction(
        Request $request,
        ConfigurationPreparationService $configurationPreparationService
    ): JsonResponse {
        //$this->checkPermission(self::CONFIG_NAME);

        $name = $request->get('name');
        $config = $configurationPreparationService->prepareConfiguration($name);

        return new JsonResponse(
            [
                'name' => 'test',
                'configuration' => $config,
                'userPermissions' => [
                    'update' => 'true',
                    'delete' => 'true'
                ],
                'columnHeaders' => [
                    ["id" => 0, "dataIndex" => 0, "label" => 'header0'],
                    ["id" => 1, "dataIndex" => 1, "label" => 'header1'],
                    ["id" => 2, "dataIndex" => 2, "label" => 'header2']
                ],
                'modificationDate' => $config['general']['modificationDate']
            ]
        );
    }

    /**
     * @Route("/save", name="_save")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    public function saveAction(Request $request): ?JsonResponse
    {
        $data = $request->get('data');
        $modificationDate = $request->get('modificationDate', 0);

        $dataDecoded = json_decode($data, true);

        $name = $dataDecoded['general']['name'];
        $dataDecoded['general']['active'] = $dataDecoded['general']['active'] ?? false;
        $config = Configuration::getByName($name);
        if (!$config->isAllowed('update')) {
            throw new AccessDeniedHttpException("You do not have permission to modify this configuration");
        }

        $savedModificationDate = 0;
        $configuration = $config->getConfiguration();
        if ($configuration && isset($configuration['general']['modificationDate'])) {
            $savedModificationDate = $configuration['general']['modificationDate'];
        }

        if ($modificationDate < $savedModificationDate) {
            throw new \Exception('The configuration was modified during editing, please reload the configuration and make your changes again');
        }

        $config->setConfiguration($dataDecoded);

        // @phpstan-ignore-next-line isAllowed return can changed now
        if ($config->isAllowed('read') && $config->isAllowed('update')) {
            $config->save();

            return $this->json(['success' => true, 'modificationDate' => $config->getConfiguration()['general']['modificationDate']]);
        } else {
            return $this->json(['success' => false, 'permissionError' => true]);
        }
    }
}

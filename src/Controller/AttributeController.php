<?php

namespace TorqIT\StoreSyndicatorBundle\Controller;

use Google\Service\SecurityCommandCenter\Access;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Controller\FrontendController;
use Pimcore\Model\DataObject\ClassDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;
use TorqIT\StoreSyndicatorBundle\Services\Authenticators\AbstractAuthenticator;

#[Route(path: '/admin/storesyndicator/attributes', name: 'pimcore_storesyndicator_attributes')]
class AttributeController extends FrontendController
{
    /**
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    #[Route(path: '/getLocal', name: '_get_local')]
    public function getLocalAttributes(Request $request, AttributesService $attributesService): JsonResponse
    {
        $name = $request->get("name");
        $config = Configuration::getByName($name);

        $fields = $attributesService->getLocalFields($config);

        $data = [];
        foreach ($fields as $field) {
            $data[] = ['name' => $field];
        }
        $result = $this->json($data);
        return $result;
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    #[Route(path: '/getRemote', name: '_get_remote')]
    public function getRemoteAttributes(Request $request, AttributesService $attributesService): JsonResponse
    {
        $name = $request->get("name");
        $config = Configuration::getByName($name);
        $path = $config->getConfiguration()["APIAccess"] ?? false ? $config->getConfiguration()["APIAccess"][0]["cpath"] : null;
        $accessObject = $path ? DataObject::getByPath($path) : null;
        if ($accessObject instanceof AbstractAuthenticator) {
            $client = $accessObject->connect()['client'];
            $fields = $attributesService->getRemoteFields($client);

            return $this->json($fields);
        } else {
            return $this->json([]);
        }
    }

    /**
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    #[Route(path: '/getRemoteTypes', name: '_get_remote_types')]
    public function getRemoteAttributeTypes(Request $request, AttributesService $attributesService): JsonResponse
    {
        $fields = $attributesService->getRemoteTypes();

        $data = [];
        foreach ($fields as $field) {
            $data[] = ['name' => $field];
        }
        return $this->json($data);
    }
}

<?php

namespace TorqIT\StoreSyndicatorBundle\Controller;

use Pimcore\Model\DataObject\Concrete;
use Pimcore\Controller\FrontendController;
use Pimcore\Model\DataObject\ClassDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use TorqIT\StoreSyndicatorBundle\Services\AttributesService;

/**
 * @Route("/admin/storesyndicator/attributes", name="pimcore_storesyndicator_attributes")
 */
class AttributeController extends FrontendController
{
    /**
     * @Route("/getLocal", name="_get_local")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
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
     * @Route("/getRemote", name="_get_remote")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    public function getRemoteAttributes(Request $request, AttributesService $attributesService): JsonResponse
    {
        $name = $request->get("name");
        $config = Configuration::getByName($name);

        $fields = $attributesService->getRemoteFields($config);

        $data = [];
        foreach ($fields as $field) {
            $data[] = ['name' => $field];
        }
        return $this->json($data);
    }
}

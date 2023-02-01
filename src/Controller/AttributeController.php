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
use TorqIT\StoreSyndicatorBundle\Services\ShopifyAttributesService;

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
    public function getLocalAttributes(Request $request): JsonResponse
    {
        $name = $request->get("name");
        $config = Configuration::getByName($name);
        $config = $config->getConfiguration();

        $class = $config["products"]["class"];
        $class = ClassDefinition::getByName($class);

        $fields = $class->getFieldDefinitions();
        $data = [];
        foreach ($fields as $field) {
            $data[] = ['name' => $field->getName()];
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
    public function getRemoteAttributes(Request $request, ShopifyAttributesService $shopifyAttributesService): JsonResponse
    {
        $name = $request->get("name");
        $config = Configuration::getByName($name);

        $fields = $shopifyAttributesService->getRemoteFields($config);

        $data = [];
        foreach ($fields as $field) {
            $data[] = ['name' => $field];
        }
        return $this->json($data);
    }
}

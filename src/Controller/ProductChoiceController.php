<?php

namespace TorqIT\StoreSyndicatorBundle\Controller;

use Pimcore\Model\DataObject;
use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Pimcore\Bundle\AdminBundle\Controller\Admin\DataObject\DataObjectController;

/**
 * @Route("/admin/storesyndicator/productchoice", name="pimcore_storesyndicator_product_choice")
 */
class ProductChoiceController extends FrontendController
{
    /**
     * @Route("/getClasses", name="_get_classes")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    public function getClassList(): JsonResponse
    {
        $classesList = new DataObject\ClassDefinition\Listing();
        $classesList->setOrderKey('name');
        $classesList->setOrder('asc');
        $classes = $classesList->load();

        $response = [];
        foreach ($classes as $class) {
            $response[] = ["name" => $class->getName()];
        }
        return $this->json($response);
    }

    /**
     * @Route("/getObjects", name="_get_objects")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    public function getObjectList(): JsonResponse
    {
        $classesList = new DataObject\ClassDefinition\Listing();
        $classesList->setOrderKey('name');
        $classesList->setOrder('asc');
        $classes = $classesList->load();

        $response = [];
        foreach ($classes as $class) {
            $response[] = ["name" => $class->getName(), "id" => $class->getId()];
        }
        return $this->json($response);
    }

    /**
     * @Route("/getTree", name="_get_tree")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    public function getObjectTree(Request $request): JsonResponse
    {
        $response = $this->forward('Pimcore\Bundle\AdminBundle\Controller\Admin\DataObject\DataObjectController::treeGetChildsByIdAction', [
            'request' => $request
        ]);
        $name = $request->get('name');
        $config = Configuration::getByName($name);
        $config = $config->getConfiguration();
        $responseData = json_decode($response->getContent());
        foreach ($responseData->nodes as $ind => $node) {
            if ($node->type != 'folder' && isset($config["products"]['class']) && $node->className != $config["products"]['class']) {
                unset($responseData->nodes[$ind]);
                continue;
            }
            if (isset($config["products"]["products"]) && in_array($node->id, $config["products"]["products"])) {
                $responseData->nodes[$ind]->checked = true;
            } else {
                $responseData->nodes[$ind]->checked = false;
            }
        }

        $response->setContent(json_encode($responseData));
        return $response;
    }
}

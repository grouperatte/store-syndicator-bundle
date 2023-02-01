<?php

namespace TorqIT\StoreSyndicatorBundle\Controller;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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
    public function getLocalAttributes(): JsonResponse
    {
        $result = $this->json([
            ['name' => 'rimSize'],
            ['name' => 'tireWidth']
        ]);
        return $result;
    }

    /**
     * @Route("/getRemote", name="_get_remote")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    public function getRemoteAttributes(): JsonResponse
    {
        return $this->json([
            ['name' => 'rim-size'],
            ['name' => 'tire-width']
        ]);
    }
}

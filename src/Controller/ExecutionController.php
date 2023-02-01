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
 * @Route("/admin/storesyndicator/execution", name="pimcore_storesyndicator_execution")
 */
class ExecutionController extends FrontendController
{
    /**
     * @Route("/execute", name="_execute")
     *
     * @param Request $request
     *
     * @return JsonResponse|null
     */
    public function executeAction(Request $request)
    {
        # figure out organization here
        return $this->json([]);
    }
}

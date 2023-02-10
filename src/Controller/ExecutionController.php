<?php

namespace TorqIT\StoreSyndicatorBundle\Controller;

use Pimcore\Controller\FrontendController;
use Symfony\Component\HttpFoundation\Request;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use TorqIT\StoreSyndicatorBundle\Services\ExecutionService;

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
    public function executeAction(Request $request, ExecutionService $executionService)
    {
        # figure out organization here
        $name = $request->get("name");
        $config = Configuration::getByName($name);

        $executionService->export($config->getConfiguration());

        return $this->json([]);
    }
}

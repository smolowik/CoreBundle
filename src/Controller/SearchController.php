<?php

// src/Controller/SearchController.php
namespace CommonGateway\CoreBundle\Controller;

use CommonGateway\CoreBundle\Service\CacheService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class SearchControllerc.
 *
 * @Route("admin/search")
 */
class SearchController extends AbstractController
{

    private CacheService $cacheService;

    public function __construct(CacheService $cacheService)
    {
        $this->cacheService = $cacheService;

    }//end __construct()

    /**
     * @Route("/", methods={"GET"})
     */
    public function installedAction(Request $request)
    {
        $status  = 200;
        $results = ['bla'];
        // $this->cacheService->getAll(['--installed'])['installed'];
        return new Response(json_encode($plugins), $status, ['Content-type' => 'application/json']);

    }//end installedAction()
}//end class

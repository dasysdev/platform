<?php

namespace Oro\Bundle\DataGridBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;

use Oro\Bundle\DataGridBundle\Provider\SystemAwareResolver;

class TestController extends Controller
{
    /**
     * @Route("/test1", name="oro_datagrid_test")
     */
    public function testAction()
    {
        $parser = new SystemAwareResolver($this->container);
        $parser->parse(
            '/home/web/orocrm.dev.lxc/src/Oro/src/Oro/Bundle/EmailBundle/Resources/config/datagrid.yml'
        );

        die('dsf');
    }
}

<?php

namespace Alchemy\Tests\Phrasea\Controller\Api;

use Symfony\Component\HttpKernel\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group functional
 * @group legacy
 * @group web
 */
class ApiRootTest extends \PhraseanetWebTestCase
{
    /**
     *
     * @var \Symfony\Component\HttpKernel\Client
     */
    protected $client;

    public function setUp()
    {
        parent::setUp();

        self::$DI['app'] = self::$DI->share(function ($DI) {
            return $this->loadApp('/lib/Alchemy/Phrasea/Application/Api.php');
        });
    }

    public function testRoot()
    {
        self::$DI['client']->request('GET', '/api/');

        $response = self::$DI['client']->getResponse();

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/json', $response->headers->get('content-type'));

        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('response', $data);
    }
}

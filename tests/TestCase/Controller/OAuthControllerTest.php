<?php

namespace OAuthServer\Test\TestCase\Controller;

use Cake\Controller\Component\AuthComponent;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventManager;
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;
use Cake\TestSuite\IntegrationTestCase;
use OAuthServer\Controller\OAuthController;

class TestAppController extends Controller
{
    public function initialize()
    {
        $this->loadComponent('Auth', [
            'authenticate' => [
                AuthComponent::ALL => [
                    'userModel' => 'Users',
                ],
                'OAuthServer.OAuth',
                'Form',
            ],
            'loginAction' => [
                'controller' => 'Users',
                'action' => 'login',
            ]
        ]);
    }
}

Configure::write('OAuthServer.appController', TestAppController::class);

class OAuthControllerTest extends IntegrationTestCase
{
    public $fixtures = [
        'plugin.o_auth_server.clients',
        'plugin.o_auth_server.scopes',
        'plugin.o_auth_server.access_tokens',
        'plugin.o_auth_server.sessions',
    ];

    public function setUp()
    {
        // class Router needs to be loaded in order for TestCase to automatically include routes
        // not really sure how to do it properly, this hotfix seems good enough
        Router::defaultRouteClass();

        parent::setUp();

        Router::plugin('OAuthServer', function (RouteBuilder $routes) {
            $routes->connect('/login', ['controller' => 'Users', 'action' => 'login']);
        });
    }

    public function testInstanceOfClassFromConfig()
    {
        $controller = new OAuthController();
        $this->assertInstanceOf(TestAppController::class, $controller);
    }

    public function extensions()
    {
        return [
            [null],
            ['json']
        ];
    }

    /**
     * @dataProvider extensions
     */
    public function testOauthRedirectsToAuthorize($ext)
    {
        $this->get($this->url("/oauth", $ext) . "?client_id=CID&anything=at_all");
        $this->assertRedirect(['controller' => 'OAuth', 'action' => 'authorize', '_ext' => $ext, '?' => ['client_id' => 'CID', 'anything' => 'at_all']]);
    }

    /**
     * @dataProvider extensions
     */
    public function testAuthorizeInvalidParams($ext)
    {
        $_GET = ['client_id' => 'INVALID', 'redirect_uri' => 'http://www.example.com', 'response_type' => 'code', 'scope' => 'test'];
        $this->get($this->url('/oauth/authorize', $ext) . '?' . http_build_query($_GET));
        $this->assertResponseError();
    }

    /**
     * @dataProvider extensions
     */
    public function testAuthorizeLoginRedirect($ext)
    {
        $_GET = ['client_id' => 'TEST', 'redirect_uri' => 'http://www.example.com', 'response_type' => 'code', 'scope' => 'test'];
        $this->get($this->url('/oauth/authorize', $ext) . '?' . http_build_query($_GET));
        $this->assertRedirect(['controller' => 'Users', 'action' => 'login']);
    }

    public function testOverrideOwnerModelAndOwnerId()
    {
        $this->session(['Auth.User.id' => 5]);

        EventManager::instance()->on('OAuthServer.beforeAuthorize', function () {
            return [
                'ownerModel' => 'AnotherModel',
                'ownerId' => 15,
            ];
        });

        $_GET = ['client_id' => 'TEST', 'redirect_uri' => 'http://www.example.com', 'response_type' => 'code', 'scope' => 'test'];
        $this->get('/oauth/authorize' . '?' . http_build_query($_GET));

        $this->assertEquals('AnotherModel', $this->viewVariable('ownerModel'));
        $this->assertEquals(15, $this->viewVariable('ownerId'));
    }

    private function url($path, $ext)
    {
        $ext = $ext ? ".$ext" : '';
        return $path . $ext;
    }
}

<?php

// Copyright (c) ppy Pty Ltd <contact@ppy.sh>. Licensed under the GNU Affero General Public License v3.0.
// See the LICENCE file in the repository root for full licence text.

namespace Tests;

use App\Http\Middleware\RequireScopes;
use App\Models\OAuth\Client;
use App\Models\User;
use Illuminate\Routing\Route;
use Laravel\Passport\Exceptions\MissingScopeException;
use Request;

class RequireScopesTest extends TestCase
{
    protected $next;
    protected $request;
    protected $user;

    /**
     * @dataProvider clientCredentialsTestDataProvider
     */
    public function testClientCredentials($scopes, $expectedException)
    {
        $this->setRequest(['public']);
        $this->setUser(null, $scopes);

        if ($expectedException !== null) {
            $this->expectException($expectedException);
        }

        app(RequireScopes::class)->handle($this->request, $this->next);
        $this->assertTrue(oauth_token()->isClientCredentials());
    }

    public function testClientCredentialsIsGuest()
    {
        $this->setRequest(['public']);
        $this->setUser(null, ['public']);

        app(RequireScopes::class)->handle($this->request, $this->next);
        $this->assertNull(auth()->user());
    }

    /**
     * @dataProvider clientCredentialsTestWhenAllScopeRequiredDataProvider
     */
    public function testClientCredentialsWhenAllScopeRequired($scopes, $expectedException)
    {
        $this->setRequest();
        $this->setUser(null, $scopes);

        if ($expectedException !== null) {
            $this->expectException($expectedException);
        }

        app(RequireScopes::class)->handle($this->request, $this->next);
        $this->assertTrue(oauth_token()->isClientCredentials());
    }

    public function testChatWriteScopeDoesNotAllowAuthorizeGrant()
    {
        $user = factory(User::class)->states('bot')->create();
        $client = factory(Client::class)->create(['user_id' => $user->getKey()]);

        $this->setRequest(['bot']);
        $this->setUser($user, ['bot'], $client);

        $this->expectException(MissingScopeException::class);

        app(RequireScopes::class)->handle($this->request, $this->next);
    }

    /**
     * @dataProvider chatWriteScopeRequiresClientCredentialsBotAccessDataProvider
     */
    public function testChatWriteScopeRequiresClientCredentialsBotAccess($group, $allowed)
    {
        $user = factory(User::class);
        if ($group !== null) {
            $user->states($group);
        }
        $user = $user->create();
        $client = factory(Client::class)->create(['user_id' => $user->getKey()]);

        $this->setRequest(['bot']);
        $this->setUser(null, ['bot'], $client);

        if ($allowed) {
            $this->expectNotToPerformAssertions();
        } else {
            $this->expectException(MissingScopeException::class);
        }

        app(RequireScopes::class)->handle($this->request, $this->next);
    }

    public function testRequireScopesLayered()
    {
        $userScopes = ['identify'];
        $requireScopes = ['identify'];

        $this->setRequest($requireScopes);
        $this->setUser($this->user, $userScopes);

        app(RequireScopes::class)->handle($this->request, function () use ($requireScopes) {
            app(RequireScopes::class)->handle($this->request, $this->next, ...$requireScopes);
        });

        $this->assertTrue(!oauth_token()->isClientCredentials());
    }

    public function testRequireScopesLayeredNoPermission()
    {
        $userScopes = ['somethingelse'];
        $requireScopes = ['identify'];

        $this->setRequest($requireScopes);
        $this->setUser($this->user, $userScopes);

        $this->expectException(MissingScopeException::class);
        app(RequireScopes::class)->handle($this->request, function () use ($requireScopes) {
            app(RequireScopes::class)->handle($this->request, $this->next, ...$requireScopes);
        });
    }

    public function testRequireScopesSkipped()
    {
        $userScopes = ['somethingelse'];
        $requireScopes = ['identify'];

        $this->setRequest($requireScopes, Request::create('/api/v2/changelog', 'GET'));
        $this->setUser($this->user, $userScopes);

        app(RequireScopes::class)->handle($this->request, $this->next, ...$requireScopes);
        $this->assertTrue(!oauth_token()->isClientCredentials());
    }

    /**
     * @dataProvider userScopesTestDataProvider
     */
    public function testUserScopes($requiredScopes, $userScopes, $expectedException)
    {
        $this->setRequest($requiredScopes);
        $this->setUser($this->user, $userScopes);

        if ($expectedException !== null) {
            $this->expectException($expectedException);
        }

        if ($requiredScopes === null) {
            app(RequireScopes::class)->handle($this->request, $this->next);
        } else {
            app(RequireScopes::class)->handle($this->request, $this->next, ...$requiredScopes);
        }

        $this->assertTrue(!oauth_token()->isClientCredentials());
    }

    public function clientCredentialsTestDataProvider()
    {
        return [
            'null is not a valid scope' => [null, MissingScopeException::class],
            'empty scope should fail' => [[], MissingScopeException::class],
            'public' => [['public'], null],
            'all scope is not allowed' => [['*'], MissingScopeException::class],
        ];
    }

    public function clientCredentialsTestWhenAllScopeRequiredDataProvider()
    {
        return [
            'null is not a valid scope' => [null, MissingScopeException::class],
            'empty scope should fail' => [[], MissingScopeException::class],
            'public' => [['public'], MissingScopeException::class],
            'all scope is not allowed' => [['*'], MissingScopeException::class],
        ];
    }

    public function chatWriteScopeRequiresClientCredentialsBotAccessDataProvider()
    {
        return [
            [null, false],
            ['admin', false],
            ['bng', false],
            ['bot', true],
            ['gmt', false],
            ['nat', false],
        ];
    }

    public function userScopesTestDataProvider()
    {
        return [
            'null is not a valid scope' => [null, null, MissingScopeException::class],
            'No scopes' => [null, [], MissingScopeException::class],
            'All scopes' => [null, ['*'], null],
            'Has the required scope' => [['identify'], ['identify'], null],
            'Does not have the required scope' => [['identify'], ['somethingelse'], MissingScopeException::class],
            'Requires specific scope and all scope' => [['identify'], ['*'], null],
            'Requires specific scope and no scope' => [['identify'], [], MissingScopeException::class],
            'Requires specific scope and multiple non-matching scopes' => [['identify'], ['somethingelse', 'alsonotright', 'nope'], MissingScopeException::class],
            'Requires specific scope and multiple scopes' => [['identify'], ['somethingelse', 'identify', 'nope'], null],
            'Blank require should deny regular scopes' => [null, ['identify'], MissingScopeException::class],

        ];
    }

    protected function setRequest(?array $scopes = null, $request = null)
    {
        $this->request = $request ?? Request::create('/api/_fake', 'GET');

        $this->next = static function () {
            // just an empty closure.
        };

        // so request() works
        $this->app->instance('request', $this->request);

        // set a fake route resolver
        $this->request->setRouteResolver(function () use ($scopes) {
            $route = new Route(['GET'], '/api/_fake', null);
            $route->middleware('require-scopes');

            if ($scopes !== null) {
                $route->middleware('require-scopes:'.implode(',', $scopes));
            }

            return $route;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        // nearly all the tests in the class need a user, so might as well set it up here.
        $this->user = factory(User::class)->create();
    }

    protected function setUser(?User $user, ?array $scopes = null, $client = null)
    {
        if ($client === null) {
            $client = factory(Client::class)->create();
        }

        $token = $client->tokens()->create([
            'id' => uniqid(),
            'revoked' => false,
            'scopes' => $scopes,
            'user_id' => optional($user)->getKey(),
        ]);

        $this->actAsUserWithToken($token);
    }
}

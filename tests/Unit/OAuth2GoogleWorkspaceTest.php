<?php

declare(strict_types=1);

use League\OAuth2\Client\Provider\GenericProvider;
use PHPUnit\Framework\TestCase;
use TeampassClasses\OAuth2Controller\OAuth2Controller;

/**
 * Google Workspace (Google Identity) support of OAuth2Controller.
 *
 * Covers the provider wiring (fixed Google endpoints, OpenID Connect scopes),
 * the Workspace domain restriction enforced on the callback, and the mapping of a
 * Google UserInfo response, which carries no `preferred_username` claim.
 *
 * No network call: building an authorization URL is local for GenericProvider.
 */
class OAuth2GoogleWorkspaceTest extends TestCase
{
    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'cpassman_url' => 'https://teampass.example.com/',
            'oauth2_client_id' => 'client-id',
            'oauth2_client_secret' => 'client-secret',
            // Holds the Workspace domain for Google, and the tenant for Entra
            'oauth2_tenant_id' => 'example.com',
            'oauth2_client_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            // Entra leftovers on purpose: none of these may reach the Google request
            'oauth2_client_token' => 'https://login.microsoftonline.com/tenant/oauth2/v2.0/token',
            'oauth2_client_urlResourceOwnerDetails' => 'https://graph.microsoft.com/v1.0/me',
            'oauth2_client_scopes' => 'openid,profile,email,User.Read,Group.Read.All',
        ], $overrides);
    }

    private function provider(OAuth2Controller $controller): object
    {
        $property = new ReflectionProperty(OAuth2Controller::class, 'provider');
        $property->setAccessible(true);

        return $property->getValue($controller);
    }

    /**
     * Build the authorization request the redirect() method would send.
     *
     * @return array<string, string>
     */
    private function authorizationQuery(OAuth2Controller $controller): array
    {
        $method = new ReflectionMethod(OAuth2Controller::class, 'authorizationOptions');
        $method->setAccessible(true);

        $provider = $this->provider($controller);
        $this->assertInstanceOf(GenericProvider::class, $provider);

        $url = $provider->getAuthorizationUrl($method->invoke($controller));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function testGoogleProviderUsesGoogleEndpointsAndOpenIdScopes(): void
    {
        $controller = new OAuth2Controller($this->settings());
        $provider = $this->provider($controller);

        $this->assertInstanceOf(GenericProvider::class, $provider);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth', $provider->getBaseAuthorizationUrl());
        $this->assertSame('https://oauth2.googleapis.com/token', $provider->getBaseAccessTokenUrl([]));
        $this->assertSame(
            'https://openidconnect.googleapis.com/v1/userinfo',
            $provider->getResourceOwnerDetailsUrl(new \League\OAuth2\Client\Token\AccessToken(['access_token' => 'x']))
        );

        // The Microsoft Graph scopes of an existing Entra setup must be dropped:
        // Google would reject them. The scope setting is deliberately not reused.
        $this->assertSame(['openid', 'email', 'profile'], $provider->getDefaultScopes());
    }

    public function testGoogleAuthorizationRequestIsSpaceDelimitedAndCarriesTheDomainHint(): void
    {
        $query = $this->authorizationQuery(new OAuth2Controller($this->settings()));

        $this->assertSame('openid email profile', $query['scope']);
        // hd pre-filters the account chooser; the domain itself is enforced on the callback
        $this->assertSame('example.com', $query['hd']);
        $this->assertSame('code', $query['response_type']);
    }

    public function testGoogleRedirectUriIgnoresTrailingSlashOfTeampassUrl(): void
    {
        $withSlash = $this->authorizationQuery(new OAuth2Controller($this->settings()));
        $withoutSlash = $this->authorizationQuery(new OAuth2Controller($this->settings([
            'cpassman_url' => 'https://teampass.example.com',
        ])));

        $this->assertSame(
            'https://teampass.example.com/index.php?post_type=oauth2',
            $withSlash['redirect_uri']
        );
        $this->assertSame($withSlash['redirect_uri'], $withoutSlash['redirect_uri']);
    }

    public function testGoogleProviderRefusesToStartWithoutAHostedDomain(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('A Google Workspace domain is mandatory.');

        new OAuth2Controller($this->settings(['oauth2_tenant_id' => '']));
    }

    public function testGoogleUserOfTheExpectedDomainIsAllowed(): void
    {
        $controller = new OAuth2Controller($this->settings());

        $controller->assertGoogleAccountIsAllowed([
            'sub' => '10769150350006150715113082367',
            'hd' => 'example.com',
            'email' => 'jsmith@example.com',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testGoogleUserOfAnotherDomainIsRefused(): void
    {
        $controller = new OAuth2Controller($this->settings());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not belong to the allowed Workspace domain');

        $controller->assertGoogleAccountIsAllowed([
            'sub' => '1',
            'hd' => 'another-company.com',
            'email' => 'intruder@another-company.com',
        ]);
    }

    public function testPersonalGoogleAccountWithoutHostedDomainIsRefused(): void
    {
        $controller = new OAuth2Controller($this->settings());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not belong to the allowed Workspace domain');

        // Google omits `hd` for accounts that belong to no Cloud organization,
        // and documents that the email domain must not be used to identify them.
        $controller->assertGoogleAccountIsAllowed([
            'sub' => '2',
            'email' => 'someone@gmail.com',
        ]);
    }

    public function testHostedDomainComparisonIsCaseInsensitive(): void
    {
        $claims = ['sub' => '3', 'hd' => 'Example.COM'];

        $this->assertTrue(OAuth2Controller::googleHostedDomainIsAllowed($claims, 'example.com'));
        $this->assertTrue(OAuth2Controller::googleHostedDomainIsAllowed($claims, ' example.com '));
        $this->assertFalse(OAuth2Controller::googleHostedDomainIsAllowed($claims, 'other.com'));
    }

    public function testGoogleUserInfoWithoutPreferredUsernameFallsBackToTheEmail(): void
    {
        $user = OAuth2Controller::mapGenericUserInfo([
            'sub' => '10769150350006150715113082367',
            'hd' => 'example.com',
            'email' => 'jsmith@example.com',
            'email_verified' => true,
            'name' => 'John Smith',
            'given_name' => 'John',
            'family_name' => 'Smith',
            'picture' => 'https://example.com/jsmith.png',
        ]);

        // The login flow takes the part before '@' of this value
        $this->assertSame('jsmith@example.com', $user['userPrincipalName']);
        $this->assertSame('John', $user['givenname']);
        $this->assertSame('Smith', $user['surname']);
        $this->assertSame('jsmith@example.com', $user['mail']);
        $this->assertSame(
            '10769150350006150715113082367',
            $user['sub'],
            'sub must be kept: the login page derives the account password from it'
        );
        $this->assertSame([], $user['groups']);
    }

    public function testPreferredUsernameStillWinsOverEmailForOtherProviders(): void
    {
        $user = OAuth2Controller::mapGenericUserInfo([
            'sub' => '2d4e6f8a',
            'preferred_username' => 'jdoe',
            'email' => 'john.doe@example.com',
        ]);

        $this->assertSame('jdoe', $user['userPrincipalName']);
    }

    public function testGoogleAccountWithoutAnyUsernameIsRefused(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no "preferred_username" or "email" claim');

        OAuth2Controller::mapGenericUserInfo(['sub' => '4']);
    }

    public function testDirectorySynchronizationStillRefusesGoogle(): void
    {
        $result = (new OAuth2Controller($this->settings()))->getAllUsers();

        $this->assertTrue($result['error']);
        $this->assertSame(
            'User synchronization is only supported with Microsoft Entra ID (Azure).',
            $result['message']
        );
        $this->assertSame([], $result['users']);
    }

    public function testEntraConfigurationIsLeftUntouched(): void
    {
        $controller = new OAuth2Controller($this->settings([
            'oauth2_client_endpoint' => 'https://login.microsoftonline.com/tenant/oauth2/v2.0/authorize',
            'oauth2_tenant_id' => '00000000-0000-0000-0000-000000000000',
            'oauth2_client_scopes' => 'openid,profile,email,User.Read,Group.Read.All',
        ]));

        $provider = $this->provider($controller);
        $this->assertInstanceOf(\TheNetworg\OAuth2\Client\Provider\Azure::class, $provider);
        $this->assertSame(
            ['openid', 'profile', 'email', 'User.Read', 'Group.Read.All'],
            $provider->scope
        );

        // No Google-only option may leak into an Entra request
        $method = new ReflectionMethod(OAuth2Controller::class, 'authorizationOptions');
        $method->setAccessible(true);
        $this->assertArrayNotHasKey('hd', $method->invoke($controller));
    }
}

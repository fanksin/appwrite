<?php

namespace Tests\E2E\Services\Account;

use Appwrite\Extend\Exception;
use Appwrite\SMS\Adapter\Mock;
use Appwrite\Tests\Retry;
use Tests\E2E\Client;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;
use Utopia\Database\DateTime;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Datetime as DatetimeValidator;

use function sleep;

class AccountCustomClientTest extends Scope
{
    use AccountBase;
    use ProjectCustom;
    use SideClient;

    public function testCreateAccountWithInvite(): void
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name';

        /**
         * Test for FAILURE
         * Make sure the invite endpoint is only accessible through the console project.
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/invite', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
            'code' => 'Invalid Code'
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);
        $this->assertEquals($response['body']['type'], Exception::GENERAL_ACCESS_FORBIDDEN);
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testCreateOAuth2AccountSession(): array
    {
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        /**
         * Test for Failure when disabled
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => false,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $this->assertEquals(412, $response['headers']['status-code']);

        return [];
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testCreateAccountTarget(array $data): array
    {
        $session = $data['session'] ?? '';
        $apiKey = $this->getProject()['apiKey'];

        $provider = $this->client->call(Client::METHOD_POST, '/messaging/providers/sendgrid', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $apiKey,
        ], [
            'name' => 'Sengrid1',
            'apiKey' => 'my-apikey'
        ]);
        $this->assertEquals(201, $provider['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_POST, '/account/targets', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'targetId' => ID::unique(),
            'providerId' => $provider['body']['$id'],
            'identifier' => 'my-token',
        ]);
        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertEquals($provider['body']['$id'], $response['body']['providerId']);
        $this->assertEquals('my-token', $response['body']['identifier']);
        return ['target' => $response['body'], 'session' => $session];
    }

    /**
     * @depends testCreateAccountTarget
     */
    public function testUpdateAccountTarget(array $data): array
    {
        $session = $data['session'] ?? '';
        $target = $data['target'];
        $response = $this->client->call(Client::METHOD_PATCH, '/account/targets/' . $target['$id'] . '/identifier', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'identifier' => 'my-updated-token',
        ]);
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('my-updated-token', $response['body']['identifier']);
        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testListAccountTarget(array $data)
    {
        $session = $data['session'] ?? '';
        $response = $this->client->call(Client::METHOD_GET, '/account/targets', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(1, $response['body']['total']);
    }

    /**
     * @depends testCreateAccountTarget
     */
    public function testGetAccountTarget(array $data)
    {
        $session = $data['session'] ?? '';
        $target = $data['target'];

        $response = $this->client->call(Client::METHOD_GET, '/account/targets/' . $target['$id'], array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals($data['target']['$id'], $response['body']['$id']);
    }

    /**
     * @depends testUpdateAccountTarget
     */
    public function testDeleteAccountTarget(array $data)
    {
        $session = $data['session'] ?? '';
        $target = $data['target'];

        $response = $this->client->call(Client::METHOD_DELETE, '/account/targets/' . $target['$id'], array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));
        $this->assertEquals(204, $response['headers']['status-code']);
        $response = $this->client->call(Client::METHOD_GET, '/account/targets', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));
        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals(0, $response['body']['total']);
    }

    public function testBlockedAccount(): array
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name (blocked)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $sessionId = $response['body']['$id'];
        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_PATCH, '/users/' . $id . '/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'status' => false,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return [];
    }


    public function testSelfBlockedAccount(): array
    {
        $email = uniqid() . 'user55@localhost.test';
        $password = 'password';
        $name = 'User Name (self blocked)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/status', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ], [
            'status' => false,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertStringContainsString('a_session_' . $this->getProject()['$id'] . '=deleted', $response['headers']['set-cookie']);
        $this->assertEquals('[]', $response['headers']['x-fallback-cookies']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 401);

        return [];
    }

    public function testCreateJWT(): array
    {
        $email = uniqid() . 'user@localhost.test';
        $password = 'password';
        $name = 'User Name (JWT)';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);

        $id = $response['body']['$id'];

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $sessionId = $response['body']['$id'];
        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_POST, '/account/jwt', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 201);
        $this->assertEquals($response['headers']['x-ratelimit-remaining'], 99);
        $this->assertNotEmpty($response['body']['jwt']);
        $this->assertIsString($response['body']['jwt']);

        $jwt = $response['body']['jwt'];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => 'wrong-token',
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_DELETE, '/account/sessions/' . $sessionId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 204);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-jwt' => $jwt,
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        return [];
    }

    public function testCreateAnonymousAccount()
    {
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);

        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        \usleep(1000 * 30); // wait for 30ms to let the shutdown update accessedAt

        $apiKey = $this->getProject()['apiKey'];
        $userId = $response['body']['userId'];
        $response = $this->client->call(Client::METHOD_GET, '/users/' . $userId, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $apiKey,
        ]));
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertArrayHasKey('accessedAt', $response['body']);
        $this->assertNotEmpty($response['body']['accessedAt']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/anonymous', [
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $session;
    }

    /**
     * @depends testCreateAnonymousAccount
     */
    public function testUpdateAnonymousAccountPassword($session)
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/password', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'oldPassword' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return $session;
    }

    /**
     * @depends testUpdateAnonymousAccountPassword
     */
    public function testUpdateAnonymousAccountEmail($session)
    {
        $email = uniqid() . 'new@localhost.test';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => '',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        return [];
    }

    public function testConvertAnonymousAccount()
    {
        $session = $this->testCreateAnonymousAccount();
        $email = uniqid() . 'new@localhost.test';
        $password = 'new-password';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'email' => $email,
            'password' => $password
        ]);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 409);

        /**
         * Test for SUCCESS
         */
        $email = uniqid() . 'new@localhost.test';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        $response = $this->client->call(Client::METHOD_POST, '/account/verification', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'url' => 'http://localhost'
        ]);


        $this->assertEquals($response['headers']['status-code'], 201);

        return [];
    }

    public function testConvertAnonymousAccountOAuth2()
    {
        $session = $this->testCreateAnonymousAccount();
        $provider = 'mock';
        $appId = '1';
        $secret = '123456';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);

        $userId = $response['body']['$id'] ?? '';

        $response = $this->client->call(Client::METHOD_PATCH, '/projects/' . $this->getProject()['$id'] . '/oauth2', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
        ]), [
            'provider' => $provider,
            'appId' => $appId,
            'secret' => $secret,
            'enabled' => true,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/oauth2/' . $provider, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'success' => 'http://localhost/v1/mock/tests/general/oauth2/success',
            'failure' => 'http://localhost/v1/mock/tests/general/oauth2/failure',
        ]);

        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('success', $response['body']['result']);

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['$id'], $userId);
        $this->assertEquals($response['body']['name'], 'User Name');
        $this->assertEquals($response['body']['email'], 'useroauth@localhost.test');

        // Since we only support one oauth user, let's also check updateSession here

        $this->assertEquals($response['headers']['status-code'], 200);

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('123456', $response['body']['providerAccessToken']);
        $this->assertEquals('tuvwxyz', $response['body']['providerRefreshToken']);
        $this->assertGreaterThan(DateTime::addSeconds(new \DateTime(), 14400 - 5), $response['body']['providerAccessTokenExpiry']); // 5 seconds allowed networking delay

        $initialExpiry = $response['body']['providerAccessTokenExpiry'];

        sleep(3);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals(200, $response['headers']['status-code']);
        $this->assertEquals('123456', $response['body']['providerAccessToken']);
        $this->assertEquals('tuvwxyz', $response['body']['providerRefreshToken']);
        $this->assertNotEquals($initialExpiry, $response['body']['providerAccessTokenExpiry']);

        return [];
    }

    public function testGetSessionByID()
    {
        $session = $this->testCreateAnonymousAccount();

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/current', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['provider'], 'anonymous');

        $sessionID = $response['body']['$id'];

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/' . $sessionID, array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertEquals($response['body']['provider'], 'anonymous');

        $response = $this->client->call(Client::METHOD_GET, '/account/sessions/97823askjdkasd80921371980', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 404);
    }

    /**
     * @depends testUpdateAccountName
     */
    public function testUpdateAccountNameSearch($data): void
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';
        $newName = 'Lorem';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $newName,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $id,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);
    }

    /**
     * @depends testUpdateAccountEmail
     */
    public function testUpdateAccountEmailSearch($data): void
    {
        $id = $data['id'] ?? '';
        $email = $data['email'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => '"' . $email . '"',

        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);

        $response = $this->client->call(Client::METHOD_GET, '/users', [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ], [
            'search' => $id,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['users']);
        $this->assertCount(1, $response['body']['users']);
        $this->assertEquals($response['body']['users'][0]['email'], $email);
    }


    public function testCreatePhone(): array
    {
        $number = '+123456789';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique(),
            'phone' => $number,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        $userId = $response['body']['userId'];

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::unique()
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);

        \sleep(5);

        $smsRequest = $this->getLastRequest();

        $this->assertEquals('http://request-catcher:5000/mock-sms', $smsRequest['url']);
        $this->assertEquals('Appwrite Mock Message Sender', $smsRequest['headers']['User-Agent']);
        $this->assertEquals('username', $smsRequest['headers']['X-Username']);
        $this->assertEquals('password', $smsRequest['headers']['X-Key']);
        $this->assertEquals('POST', $smsRequest['method']);
        $this->assertEquals('+123456789', $smsRequest['data']['from']);
        $this->assertEquals($number, $smsRequest['data']['to']);

        $data['token'] = $smsRequest['data']['message'];
        $data['id'] = $userId;
        $data['number'] = $number;

        return $data;
    }

    /**
     * @depends testCreatePhone
     */
    public function testCreateSessionWithPhone(array $data): array
    {
        $id = $data['id'] ?? '';
        $token = $data['token'] ?? '';
        $number = $data['number'] ?? '';

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $token,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => 'sdasdasdasd',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertNotEmpty($response['body']['userId']);

        $session = $this->client->parseCookie((string)$response['headers']['set-cookie'])['a_session_' . $this->getProject()['$id']];

        $response = $this->client->call(Client::METHOD_GET, '/account', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['phone'], $number);
        $this->assertTrue($response['body']['phoneVerification']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/sessions/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'userId' => $id,
            'secret' => $token,
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        $data['session'] = $session;

        return $data;
    }

    /**
     * @depends testCreateSessionWithPhone
     */
    public function testConvertPhoneToPassword(array $data): array
    {
        $session = $data['session'];
        $email = uniqid() . 'new@localhost.test';
        $password = 'new-password';

        /**
         * Test for SUCCESS
         */
        $email = uniqid() . 'new@localhost.test';

        $response = $this->client->call(Client::METHOD_PATCH, '/account/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['email'], $email);

        $response = $this->client->call(Client::METHOD_POST, '/account/sessions/email', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]), [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertEquals($response['headers']['status-code'], 201);

        return $data;
    }

    /**
     * @depends testConvertPhoneToPassword
     */
    public function testUpdatePhone(array $data): array
    {
        $newPhone = '+45632569856';
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'phone' => $newPhone,
            'password' => 'new-password'
        ]);

        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']);
        $this->assertNotEmpty($response['body']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['registration']));
        $this->assertEquals($response['body']['phone'], $newPhone);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        $response = $this->client->call(Client::METHOD_PATCH, '/account/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), []);

        $this->assertEquals($response['headers']['status-code'], 400);

        $data['phone'] = $newPhone;

        return $data;
    }

    /**
     * @depends testUpdatePhone
     */
    #[Retry(count: 1)]
    public function testPhoneVerification(array $data): array
    {
        $session = $data['session'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_POST, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,

        ]));

        $this->assertEquals(201, $response['headers']['status-code']);
        $this->assertNotEmpty($response['body']['$id']);
        $this->assertEmpty($response['body']['secret']);
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['expire']));

        \sleep(2);

        $smsRequest = $this->getLastRequest();

        return \array_merge($data, [
            'token' => $smsRequest['data']['message']
        ]);
    }

    /**
     * @depends testPhoneVerification
     */
    public function testUpdatePhoneVerification($data): array
    {
        $id = $data['id'] ?? '';
        $session = $data['session'] ?? '';
        $secret = $data['token'] ?? '';

        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => $secret,
        ]);

        $this->assertEquals(200, $response['headers']['status-code']);

        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => ID::custom('ewewe'),
            'secret' => $secret,
        ]);

        $this->assertEquals(404, $response['headers']['status-code']);

        $response = $this->client->call(Client::METHOD_PUT, '/account/verification/phone', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'userId' => $id,
            'secret' => '999999',
        ]);

        $this->assertEquals(401, $response['headers']['status-code']);

        return $data;
    }

    /**
     * @depends testCreateAccountSession
     */
    public function testGetAccountLogs($data): array
    {
        sleep(10);
        $session = $data['session'] ?? '';
        $sessionId = $data['sessionId'] ?? '';
        $userId = $data['id'] ?? '';
        /**
         * Test for SUCCESS
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]));
        $this->assertEquals($response['headers']['status-code'], 200);
        $this->assertIsArray($response['body']['logs']);
        $this->assertNotEmpty($response['body']['logs']);
        $this->assertCount(5, $response['body']['logs']);
        $this->assertIsNumeric($response['body']['total']);
        $this->assertContains($response['body']['logs'][1]['event'], ["targets.create"]);
        $this->assertEquals($response['body']['logs'][1]['ip'], filter_var($response['body']['logs'][1]['ip'], FILTER_VALIDATE_IP));
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['logs'][1]['time']));

        $this->assertEquals('Windows', $response['body']['logs'][1]['osName']);
        $this->assertEquals('WIN', $response['body']['logs'][1]['osCode']);
        $this->assertEquals('10', $response['body']['logs'][1]['osVersion']);

        $this->assertEquals('browser', $response['body']['logs'][1]['clientType']);
        $this->assertEquals('Chrome', $response['body']['logs'][1]['clientName']);
        $this->assertEquals('CH', $response['body']['logs'][1]['clientCode']);
        $this->assertEquals('70.0', $response['body']['logs'][1]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['logs'][1]['clientEngine']);

        $this->assertEquals('desktop', $response['body']['logs'][1]['deviceName']);
        $this->assertEquals('', $response['body']['logs'][1]['deviceBrand']);
        $this->assertEquals('', $response['body']['logs'][1]['deviceModel']);
        $this->assertEquals($response['body']['logs'][1]['ip'], filter_var($response['body']['logs'][1]['ip'], FILTER_VALIDATE_IP));

        $this->assertEquals('--', $response['body']['logs'][1]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['logs'][1]['countryName']);

        $this->assertContains($response['body']['logs'][2]['event'], ["session.create"]);
        $this->assertEquals($response['body']['logs'][2]['ip'], filter_var($response['body']['logs'][2]['ip'], FILTER_VALIDATE_IP));
        $this->assertEquals(true, (new DatetimeValidator())->isValid($response['body']['logs'][2]['time']));

        $this->assertEquals('Windows', $response['body']['logs'][2]['osName']);
        $this->assertEquals('WIN', $response['body']['logs'][2]['osCode']);
        $this->assertEquals('10', $response['body']['logs'][2]['osVersion']);

        $this->assertEquals('browser', $response['body']['logs'][2]['clientType']);
        $this->assertEquals('Chrome', $response['body']['logs'][2]['clientName']);
        $this->assertEquals('CH', $response['body']['logs'][2]['clientCode']);
        $this->assertEquals('70.0', $response['body']['logs'][2]['clientVersion']);
        $this->assertEquals('Blink', $response['body']['logs'][2]['clientEngine']);

        $this->assertEquals('desktop', $response['body']['logs'][2]['deviceName']);
        $this->assertEquals('', $response['body']['logs'][2]['deviceBrand']);
        $this->assertEquals('', $response['body']['logs'][2]['deviceModel']);
        $this->assertEquals($response['body']['logs'][2]['ip'], filter_var($response['body']['logs'][2]['ip'], FILTER_VALIDATE_IP));

        $this->assertEquals('--', $response['body']['logs'][2]['countryCode']);
        $this->assertEquals('Unknown', $response['body']['logs'][2]['countryName']);

        $responseLimit = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [ 'limit(1)' ],
        ]);

        $this->assertEquals($responseLimit['headers']['status-code'], 200);
        $this->assertIsArray($responseLimit['body']['logs']);
        $this->assertNotEmpty($responseLimit['body']['logs']);
        $this->assertCount(1, $responseLimit['body']['logs']);
        $this->assertIsNumeric($responseLimit['body']['total']);

        $this->assertEquals($response['body']['logs'][0], $responseLimit['body']['logs'][0]);

        $responseOffset = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [ 'offset(1)' ],
        ]);

        $this->assertEquals($responseOffset['headers']['status-code'], 200);
        $this->assertIsArray($responseOffset['body']['logs']);
        $this->assertNotEmpty($responseOffset['body']['logs']);
        $this->assertCount(4, $responseOffset['body']['logs']);
        $this->assertIsNumeric($responseOffset['body']['total']);

        $this->assertEquals($response['body']['logs'][1], $responseOffset['body']['logs'][0]);

        $responseLimitOffset = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'cookie' => 'a_session_' . $this->getProject()['$id'] . '=' . $session,
        ]), [
            'queries' => [ 'limit(1)', 'offset(1)' ],
        ]);

        $this->assertEquals($responseLimitOffset['headers']['status-code'], 200);
        $this->assertIsArray($responseLimitOffset['body']['logs']);
        $this->assertNotEmpty($responseLimitOffset['body']['logs']);
        $this->assertCount(1, $responseLimitOffset['body']['logs']);
        $this->assertIsNumeric($responseLimitOffset['body']['total']);

        $this->assertEquals($response['body']['logs'][1], $responseLimitOffset['body']['logs'][0]);
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/account/logs', array_merge([
            'origin' => 'http://localhost',
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ]));

        $this->assertEquals($response['headers']['status-code'], 401);

        return $data;
    }
}

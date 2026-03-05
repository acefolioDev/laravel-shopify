<?php

namespace LaravelShopify\Tests\Unit;

use Illuminate\Http\Request;
use LaravelShopify\Auth\SessionToken;
use LaravelShopify\Tests\TestCase;

class SessionTokenTest extends TestCase
{
    private SessionToken $sessionToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionToken = new SessionToken('test-api-key', 'test-api-secret');
    }

    public function test_extract_token_from_bearer_header(): void
    {
        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer my-test-token');

        $token = $this->sessionToken->extractFromRequest($request);

        $this->assertEquals('my-test-token', $token);
    }

    public function test_extract_token_from_query_param(): void
    {
        $request = Request::create('/test?id_token=query-token', 'GET');

        $token = $this->sessionToken->extractFromRequest($request);

        $this->assertEquals('query-token', $token);
    }

    public function test_returns_null_when_no_token(): void
    {
        $request = Request::create('/test', 'GET');

        $token = $this->sessionToken->extractFromRequest($request);

        $this->assertNull($token);
    }

    public function test_bearer_header_takes_precedence_over_query(): void
    {
        $request = Request::create('/test?id_token=query-token', 'GET');
        $request->headers->set('Authorization', 'Bearer header-token');

        $token = $this->sessionToken->extractFromRequest($request);

        $this->assertEquals('header-token', $token);
    }

    public function test_decode_valid_token(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com');

        $payload = $this->sessionToken->decode($jwt);

        $this->assertEquals('https://my-shop.myshopify.com/admin', $payload->iss);
        $this->assertEquals('https://my-shop.myshopify.com', $payload->dest);
        $this->assertEquals('test-api-key', $payload->aud);
    }

    public function test_decode_rejects_expired_token(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com', exp: time() - 100);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expired token');

        $this->sessionToken->decode($jwt);
    }

    public function test_decode_rejects_wrong_audience(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com', aud: 'wrong-api-key');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('aud');

        $this->sessionToken->decode($jwt);
    }

    public function test_decode_rejects_invalid_signature(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com');
        // Tamper with the signature
        $jwt .= 'tampered';

        $this->expectException(\UnexpectedValueException::class);

        $this->sessionToken->decode($jwt);
    }

    public function test_get_shop_domain(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com');
        $payload = $this->sessionToken->decode($jwt);

        $domain = $this->sessionToken->getShopDomain($payload);

        $this->assertEquals('my-shop.myshopify.com', $domain);
    }

    public function test_get_session_id_offline(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com');
        $payload = $this->sessionToken->decode($jwt);

        $sessionId = $this->sessionToken->getSessionId($payload);

        $this->assertStringStartsWith('offline_my-shop.myshopify.com', $sessionId);
    }

    public function test_get_session_id_online(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com', sub: '12345');
        $payload = $this->sessionToken->decode($jwt);

        $sessionId = $this->sessionToken->getSessionId($payload);

        $this->assertEquals('online_my-shop.myshopify.com_12345', $sessionId);
    }

    public function test_is_online_with_sub(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com', sub: '12345');
        $payload = $this->sessionToken->decode($jwt);

        $this->assertTrue($this->sessionToken->isOnline($payload));
    }

    public function test_is_offline_without_sub(): void
    {
        $jwt = $this->makeSessionToken('my-shop.myshopify.com');
        $payload = $this->sessionToken->decode($jwt);

        $this->assertFalse($this->sessionToken->isOnline($payload));
    }
}

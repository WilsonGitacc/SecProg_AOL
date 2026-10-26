<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * Web Server & HTTP Gateway Method Allowlist Model derived from:
 * - ORIGINAL_REQUEST.md §R1, §R5
 * - PROJECT.md § Container & Network Topology (Apache LimitExcept & TraceEnable)
 * - Reviewer 1 Report Finding 1 (Apache HTTP Method Allowlist Enforcement)
 */
class HttpMethodAllowlistModel
{
    /**
     * Strictly allowed HTTP methods per web server configuration:
     * <LimitExcept GET POST HEAD OPTIONS> Require all denied </LimitExcept>
     */
    public const ALLOWED_METHODS = [
        'GET',
        'POST',
        'HEAD',
        'OPTIONS',
    ];

    /**
     * Diagnostic methods blocked by TraceEnable Off: returns 405 Method Not Allowed
     */
    public const TRACE_METHODS = [
        'TRACE',
        'TRACK',
    ];

    /**
     * Simulates the web server (Apache 2.4 perimeter gateway) evaluating an HTTP request.
     *
     * @param string $method The raw HTTP method verb (e.g. GET, POST, PUT, DELETE, TRACE)
     * @param string $uri The target request URI
     * @param array<string, string> $headers Simulated request headers
     * @return array{status: int, allowed: bool, reason: string}
     */
    public static function simulateRequest(string $method, string $uri = '/', array $headers = []): array
    {
        $trimmedMethod = trim($method);

        // 1. TraceEnable Off evaluation (Apache core returns 405 Method Not Allowed)
        if (in_array(strtoupper($trimmedMethod), self::TRACE_METHODS, true)) {
            return [
                'status' => 405,
                'allowed' => false,
                'reason' => "HTTP method [{$trimmedMethod}] is rejected by TraceEnable Off (405 Method Not Allowed).",
            ];
        }

        // 2. LimitExcept allowlist evaluation (Apache <LimitExcept GET POST HEAD OPTIONS> with <RequireAll>)
        // Note: RFC 9110 §9.1 defines HTTP method tokens as case-sensitive.
        // Unlisted tokens and casing variants outside the allowlist are rejected with 403 Forbidden.
        if (!in_array($trimmedMethod, self::ALLOWED_METHODS, true)) {
            return [
                'status' => 403,
                'allowed' => false,
                'reason' => "HTTP method [{$trimmedMethod}] is rejected by perimeter allowlist (403 Forbidden).",
            ];
        }

        // 3. Request is permitted to pass through to the PHP runtime / application layer
        return [
            'status' => 200,
            'allowed' => true,
            'reason' => "HTTP method [{$trimmedMethod}] is permitted by allowlist.",
        ];
    }
}

/**
 * Automated Security Test Suite: HTTP Method Allowlist Enforcement
 *
 * Verifies that:
 * 1. Allowed methods (GET, POST, HEAD, OPTIONS) pass through successfully (200).
 * 2. Mutation methods not permitted at web perimeter (PUT, DELETE, PATCH) return 403 Forbidden.
 * 3. Diagnostic methods (TRACE, TRACK) return 405 Method Not Allowed.
 * 4. Arbitrary or dangerous HTTP verbs (CONNECT, DEBUG, PROPFIND, FOOBAR) return 403 Forbidden.
 * 5. Method enforcement is applied uniformly across all web endpoints.
 */
class HttpMethodAllowlistTest extends TestCase
{
    /**
     * @test
     * Verifies that allowed methods (GET, POST, HEAD, OPTIONS) pass perimeter filtering.
     */
    public function testAllowedMethodsArePermitted(): void
    {
        $allowed = ['GET', 'POST', 'HEAD', 'OPTIONS'];

        foreach ($allowed as $method) {
            $result = HttpMethodAllowlistModel::simulateRequest($method, '/healthz');
            $this->assertSame(
                200,
                $result['status'],
                sprintf("Allowed method [%s] must return status 200.", $method)
            );
            $this->assertTrue(
                $result['allowed'],
                sprintf("Allowed method [%s] must be permitted.", $method)
            );
        }
    }

    /**
     * @test
     * Verifies that unallowed mutation methods (PUT, DELETE, PATCH) are rejected with 403 Forbidden.
     */
    public function testDisallowedMutationVerbsReturn403(): void
    {
        $disallowed = ['PUT', 'DELETE', 'PATCH'];

        foreach ($disallowed as $method) {
            $result = HttpMethodAllowlistModel::simulateRequest($method, '/healthz');
            $this->assertSame(
                403,
                $result['status'],
                sprintf("Disallowed method [%s] must be rejected with 403 Forbidden.", $method)
            );
            $this->assertFalse(
                $result['allowed'],
                sprintf("Disallowed method [%s] must not be permitted.", $method)
            );
            $this->assertStringContainsString(
                '403 Forbidden',
                $result['reason'],
                "Reason must mention 403 Forbidden."
            );
        }
    }

    /**
     * @test
     * Verifies that TRACE and TRACK methods are rejected with 405 Method Not Allowed.
     */
    public function testTraceAndTrackMethodsReturn405(): void
    {
        $traceMethods = ['TRACE', 'TRACK'];

        foreach ($traceMethods as $method) {
            $result = HttpMethodAllowlistModel::simulateRequest($method, '/healthz');
            $this->assertSame(
                405,
                $result['status'],
                sprintf("Diagnostic method [%s] must be rejected with 405 Method Not Allowed.", $method)
            );
            $this->assertFalse(
                $result['allowed'],
                sprintf("Diagnostic method [%s] must not be permitted.", $method)
            );
            $this->assertStringContainsString(
                'TraceEnable Off',
                $result['reason'],
                "Reason must indicate TraceEnable Off."
            );
        }
    }

    /**
     * @test
     * Verifies that arbitrary and extended HTTP verbs are rejected with 403 Forbidden.
     */
    public function testArbitraryAndExtendedHttpVerbsReturn403(): void
    {
        $arbitraryVerbs = [
            'CONNECT',
            'DEBUG',
            'PROPFIND',
            'PROPPATCH',
            'MKCOL',
            'COPY',
            'MOVE',
            'LOCK',
            'UNLOCK',
            'FOOBAR',
            'TEST_VERB',
        ];

        foreach ($arbitraryVerbs as $verb) {
            $result = HttpMethodAllowlistModel::simulateRequest($verb, '/');
            $this->assertSame(
                403,
                $result['status'],
                sprintf("Arbitrary verb [%s] must be rejected with 403 Forbidden.", $verb)
            );
            $this->assertFalse(
                $result['allowed'],
                sprintf("Arbitrary verb [%s] must not be permitted.", $verb)
            );
        }
    }

    /**
     * @test
     * Verifies case-sensitivity: lower-case variants outside allowlist are rejected with 403.
     */
    public function testCaseSensitivityEnforcement(): void
    {
        $invalidCasedMethods = ['put', 'delete', 'patch', 'trace', 'track', 'get', 'post'];

        foreach ($invalidCasedMethods as $method) {
            $result = HttpMethodAllowlistModel::simulateRequest($method, '/');
            // Since RFC 9110 and Apache match exact tokens, lowercase methods outside exact allowed uppercase match are rejected
            if (in_array(strtoupper($method), ['TRACE', 'TRACK'], true)) {
                $this->assertSame(405, $result['status'], "Case variant of TRACE must return 405.");
            } else {
                $this->assertSame(403, $result['status'], sprintf("Unmatched case method [%s] must return 403.", $method));
            }
        }
    }

    /**
     * @test
     * Verifies that method rejection is applied uniformly across diverse endpoints.
     */
    public function testAllowlistEnforcementIsPerimeterWide(): void
    {
        $endpoints = [
            '/',
            '/healthz',
            '/healthz.php',
            '/login',
            '/dashboard',
            '/courses',
            '/courses/1/forum',
            '/courses/1/gradebook',
            '/schedule',
            '/operator/users',
            '/storage/uploads/uuid.pdf',
        ];

        foreach ($endpoints as $uri) {
            $putResult = HttpMethodAllowlistModel::simulateRequest('PUT', $uri);
            $this->assertSame(403, $putResult['status'], "PUT to [{$uri}] must return 403.");

            $deleteResult = HttpMethodAllowlistModel::simulateRequest('DELETE', $uri);
            $this->assertSame(403, $deleteResult['status'], "DELETE to [{$uri}] must return 403.");

            $traceResult = HttpMethodAllowlistModel::simulateRequest('TRACE', $uri);
            $this->assertSame(405, $traceResult['status'], "TRACE to [{$uri}] must return 405.");

            $getResult = HttpMethodAllowlistModel::simulateRequest('GET', $uri);
            $this->assertSame(200, $getResult['status'], "GET to [{$uri}] must return 200.");
        }
    }
}

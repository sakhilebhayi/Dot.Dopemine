<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Baseline browser protections are on every response, the policy only reports, and violation reports are accepted safely. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_enforced_baseline_headers(): void
    {
        $r = $this->get('/');

        $r->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $permissions = (string) $r->headers->get('Permissions-Policy');
        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()'] as $denied) {
            $this->assertStringContainsString($denied, $permissions);
        }
        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_hsts_is_sent_on_https_only_and_can_be_switched_off(): void
    {
        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'), 'plain http never gets HSTS');

        $hsts = (string) $this->get('https://localhost/')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=15552000', $hsts, 'no includeSubDomains and no preload: sibling subdomains are not all HTTPS-only');

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/')->headers->has('Strict-Transport-Security'));
    }

    public function test_a_header_set_by_the_route_is_not_overwritten(): void
    {
        Route::get('/__strict', fn () => response('x')->header('X-Frame-Options', 'DENY'));

        $this->get('/__strict')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_the_content_security_policy_only_reports_and_names_the_origins_the_pages_use(): void
    {
        $r = $this->get('/');

        $this->assertFalse($r->headers->has('Content-Security-Policy'), 'nothing is enforced yet');
        $policy = (string) $r->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com", $policy);
        $this->assertStringContainsString('https://fonts.googleapis.com', $policy);
        $this->assertStringContainsString('https://fonts.gstatic.com', $policy);
        $this->assertStringContainsString("img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com", $policy);
        $this->assertStringContainsString("connect-src 'self' wss: https:", $policy);
    }

    public function test_violation_reports_are_accepted_without_a_session_and_keep_only_the_directive_and_host(): void
    {
        Log::spy();
        $body = json_encode(['csp-report' => ['effective-directive' => 'script-src-elem', 'blocked-uri' => 'https://evil.example/steal?token=SECRET123', 'document-uri' => 'https://dopemine.example/x?code=SECRET456']]);

        $first = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body);
        $first->assertNoContent();
        $this->assertGuest();
        $this->assertCount(0, $first->headers->getCookies(), 'no session cookie is started for a report');
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();   // the same pair is logged once a day

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $ctx = []) => $m === 'csp.violation' && $ctx === ['directive' => 'script-src-elem', 'blocked_host' => 'evil.example'])->once();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], 'not json at all')->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'inline']]]))->assertNoContent();
    }

    public function test_the_report_post_needs_no_csrf_token(): void
    {
        // CSRF verification is skipped when the app runs unit tests, which would hide a 419 that only production
        // shows (a browser posts the report itself and has no token): run the request in production mode.
        $this->app['env'] = 'production';

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(['csp-report' => ['effective-directive' => 'img-src', 'blocked-uri' => 'inline']]))
            ->assertNoContent();
    }
}

<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SSRF-guard helpers in htdocs/api/lib.php.
 *
 * IP literals are used so the tests are deterministic and require no DNS.
 */
final class UrlValidationTest extends TestCase {
    public function testPublicIpv4IsPublic(): void {
        $this->assertTrue(isPublicIp('93.184.216.34'));
    }

    public function testPrivateIpv4IsNotPublic(): void {
        $this->assertFalse(isPublicIp('10.0.0.1'));
        $this->assertFalse(isPublicIp('192.168.1.1'));
        $this->assertFalse(isPublicIp('127.0.0.1'));
    }

    public function testPublicIpv6IsPublic(): void {
        $this->assertTrue(isPublicIp('2606:4700:4700::1111'));
    }

    public function testLoopbackIpv6IsNotPublic(): void {
        $this->assertFalse(isPublicIp('::1'));
    }

    public function testMalformedUrlIsRejected(): void {
        $result = validateTargetUrl('http:///no-host', false);
        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['code']);
    }

    public function testNonHttpSchemeIsRejectedBeforeDns(): void {
        $result = validateTargetUrl('ftp://example.com/cal.ics', false);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('http', $result['message']);
    }

    public function testPrivateHostLiteralIsRejected(): void {
        $result = validateTargetUrl('http://127.0.0.1/cal.ics', false);
        $this->assertFalse($result['ok']);
        $this->assertSame(400, $result['code']);
    }

    public function testLoopbackIpv6LiteralIsRejected(): void {
        $result = validateTargetUrl('http://[::1]/cal.ics', false);
        $this->assertFalse($result['ok']);
    }

    public function testPrivateHostAllowedWhenFlagSet(): void {
        $result = validateTargetUrl('http://127.0.0.1/cal.ics', true);
        $this->assertTrue($result['ok']);
    }

    public function testPublicIpLiteralIsAccepted(): void {
        $result = validateTargetUrl('http://93.184.216.34/cal.ics', false);
        $this->assertTrue($result['ok']);
    }

    public function testHttpsPublicIpLiteralIsAccepted(): void {
        $result = validateTargetUrl('https://93.184.216.34/cal.ics', false);
        $this->assertTrue($result['ok']);
    }
}

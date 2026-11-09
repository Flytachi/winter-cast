<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Tests\Unit\Client;

use Flytachi\Winter\Base\Method;
use Flytachi\Winter\Cast\Client\RetryPolicy;
use Flytachi\Winter\Cast\Client\Timeouts;
use Flytachi\Winter\Cast\Exception\InvalidArgumentException;
use Flytachi\Winter\Cast\Exception\NetworkException;
use Flytachi\Winter\Cast\Message\CastRequest;
use Flytachi\Winter\Cast\Message\CastResponse;
use Flytachi\Winter\Cast\Tests\TestCase;

final class RetryPolicyTest extends TestCase
{
    private function failure(int $errno): NetworkException
    {
        return new NetworkException('x', CastRequest::get('https://a.com'), $errno);
    }

    public function testNoneNeverRetries(): void
    {
        $policy = RetryPolicy::none();

        self::assertFalse($policy->enabled());
        self::assertFalse($policy->retriesFailure('GET', 1, $this->failure(CURLE_COULDNT_CONNECT)));
    }

    public function testIdempotentMethodsAndTransientErrorsOnly(): void
    {
        $policy = RetryPolicy::exponential(3);

        self::assertTrue($policy->retriesFailure('GET', 1, $this->failure(CURLE_COULDNT_CONNECT)));
        self::assertTrue($policy->retriesFailure('PUT', 2, $this->failure(CURLE_RECV_ERROR)));
        self::assertFalse($policy->retriesFailure('GET', 3, $this->failure(CURLE_COULDNT_CONNECT)), 'attempts used up');
        self::assertFalse($policy->retriesFailure('POST', 1, $this->failure(CURLE_COULDNT_CONNECT)), 'not idempotent');
        self::assertFalse($policy->retriesFailure('GET', 1, $this->failure(CURLE_COULDNT_RESOLVE_HOST)), 'not transient');
    }

    public function testNonIdempotentAndCustomMethods(): void
    {
        self::assertTrue(RetryPolicy::exponential()->withNonIdempotent()
            ->retriesFailure('POST', 1, $this->failure(CURLE_COULDNT_CONNECT)));
        self::assertFalse(RetryPolicy::exponential()->withMethods(Method::GET)
            ->retriesFailure('PUT', 1, $this->failure(CURLE_COULDNT_CONNECT)));
    }

    public function testStatusesAreRetriedOnlyWhenListed(): void
    {
        $response = CastResponse::fromString('', 503);

        self::assertFalse(RetryPolicy::exponential()->retriesResponse('GET', 1, $response));
        self::assertTrue(RetryPolicy::exponential()->withStatuses(503)->retriesResponse('GET', 1, $response));
    }

    public function testExponentialDelayWithinJitter(): void
    {
        $policy = RetryPolicy::exponential(5, 100);

        foreach ([1 => 100, 2 => 200, 3 => 400] as $attempt => $base) {
            $delay = $policy->delayAfter($attempt);
            self::assertGreaterThanOrEqual((int) ($base * 0.7), $delay);
            self::assertLessThanOrEqual((int) ceil($base * 1.3), $delay);
        }
    }

    public function testFixedDelayAndMaxDelay(): void
    {
        self::assertSame(250, RetryPolicy::fixed(3, 250)->delayAfter(2));
        self::assertSame(1000, RetryPolicy::fixed(3, 5000)->withMaxDelay(1000)->delayAfter(1));
    }

    public function testRetryAfterIsRespectedAndCapped(): void
    {
        $policy = RetryPolicy::fixed(3, 10)->withMaxDelay(5000);

        self::assertSame(2000, $policy->delayAfter(1, CastResponse::fromString('', 503, ['Retry-After' => '2'])));
        self::assertSame(5000, $policy->delayAfter(1, CastResponse::fromString('', 503, ['Retry-After' => '60'])));
        self::assertSame(10, $policy->respectRetryAfter(false)
            ->delayAfter(1, CastResponse::fromString('', 503, ['Retry-After' => '2'])));
    }

    public function testHugeRetryAfterIsCapped(): void
    {
        $response = CastResponse::fromString('', 503, ['Retry-After' => '99999999999999999999']);

        self::assertSame(30_000, RetryPolicy::fixed(3, 10)->delayAfter(1, $response));
    }

    public function testTimeoutsValidate(): void
    {
        self::assertNull(Timeouts::forDownload()->total);
        self::assertSame(60.0, Timeouts::forDownload()->idle);
        self::assertSame(2.0, Timeouts::of()->withDeadline(2)->deadline);

        $this->expectException(InvalidArgumentException::class);
        Timeouts::of(0);
    }
}

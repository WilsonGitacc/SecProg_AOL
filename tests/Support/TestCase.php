<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Custom Assertion Exception thrown on failure.
 */
class AssertionFailedError extends \RuntimeException
{
}

/**
 * Base TestCase providing standard PHPUnit-compatible assertions with zero dependencies.
 */
abstract class TestCase
{
    private int $assertionCount = 0;
    private ?string $expectedException = null;
    private ?string $expectedExceptionMessage = null;

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public function getAssertionCount(): int
    {
        return $this->assertionCount;
    }

    public function resetAssertionCount(): void
    {
        $this->assertionCount = 0;
    }

    public function expectException(string $exceptionClass): void
    {
        $this->expectedException = $exceptionClass;
    }

    public function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    public function getExpectedException(): ?string
    {
        return $this->expectedException;
    }

    public function getExpectedExceptionMessage(): ?string
    {
        return $this->expectedExceptionMessage;
    }

    public function clearExpectedException(): void
    {
        $this->expectedException = null;
        $this->expectedExceptionMessage = null;
    }

    protected function recordAssertion(): void
    {
        $this->assertionCount++;
    }

    protected function fail(string $message = 'Assertion failed'): void
    {
        throw new AssertionFailedError($message);
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if ($expected != $actual) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual value [%s] equals expected [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }

    public function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if ($expected == $actual) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual value [%s] does not equal [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if ($expected !== $actual) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual value [%s] is identical to expected [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }

    public function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if ($expected === $actual) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual value [%s] is not identical to [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }

    public function assertTrue(mixed $condition, string $message = ''): void
    {
        $this->recordAssertion();
        if ($condition !== true) {
            $msg = $message ?: sprintf(
                "Failed asserting that [%s] is true.",
                var_export($condition, true)
            );
            $this->fail($msg);
        }
    }

    public function assertFalse(mixed $condition, string $message = ''): void
    {
        $this->recordAssertion();
        if ($condition !== false) {
            $msg = $message ?: sprintf(
                "Failed asserting that [%s] is false.",
                var_export($condition, true)
            );
            $this->fail($msg);
        }
    }

    public function assertNull(mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if ($actual !== null) {
            $msg = $message ?: sprintf(
                "Failed asserting that [%s] is null.",
                var_export($actual, true)
            );
            $this->fail($msg);
        }
    }

    public function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if ($actual === null) {
            $msg = $message ?: "Failed asserting that value is not null.";
            $this->fail($msg);
        }
    }

    public function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if (empty($actual)) {
            $msg = $message ?: sprintf("Failed asserting that value is not empty. Actual: [%s]", var_export($actual, true));
            $this->fail($msg);
        }
    }

    public function assertEmpty(mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if (!empty($actual)) {
            $msg = $message ?: sprintf("Failed asserting that value is empty. Actual: [%s]", var_export($actual, true));
            $this->fail($msg);
        }
    }

    public function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        $this->recordAssertion();
        if (!str_starts_with($string, $prefix)) {
            $msg = $message ?: sprintf("Failed asserting that string [%s] starts with prefix [%s].", substr($string, 0, 100), $prefix);
            $this->fail($msg);
        }
    }

    public function assertStringEndsWith(string $suffix, string $string, string $message = ''): void
    {
        $this->recordAssertion();
        if (!str_ends_with($string, $suffix)) {
            $msg = $message ?: sprintf("Failed asserting that string [%s] ends with suffix [%s].", substr($string, 0, 100), $suffix);
            $this->fail($msg);
        }
    }

    public function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->recordAssertion();
        if (!str_contains($haystack, $needle)) {
            $preview = strlen($haystack) > 200 ? substr($haystack, 0, 200) . '...' : $haystack;
            $msg = $message ?: sprintf(
                "Failed asserting that haystack contains substring [%s]. Haystack was: [%s]",
                $needle,
                $preview
            );
            $this->fail($msg);
        }
    }

    public function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        $this->recordAssertion();
        if (str_contains($haystack, $needle)) {
            $msg = $message ?: sprintf(
                "Failed asserting that haystack does NOT contain substring [%s].",
                $needle
            );
            $this->fail($msg);
        }
    }

    public function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        $this->recordAssertion();
        if (preg_match($pattern, $string) !== 1) {
            $msg = $message ?: sprintf(
                "Failed asserting that [%s] matches regular expression pattern [%s].",
                substr($string, 0, 200),
                $pattern
            );
            $this->fail($msg);
        }
    }

    public function assertArrayHasKey(int|string $key, array $array, string $message = ''): void
    {
        $this->recordAssertion();
        if (!array_key_exists($key, $array)) {
            $msg = $message ?: sprintf(
                "Failed asserting that array contains key [%s].",
                $key
            );
            $this->fail($msg);
        }
    }

    public function assertCount(int $expectedCount, mixed $countable, string $message = ''): void
    {
        $this->recordAssertion();
        $actualCount = is_countable($countable) ? count($countable) : -1;
        if ($actualCount !== $expectedCount) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual count [%d] matches expected count [%d].",
                $actualCount,
                $expectedCount
            );
            $this->fail($msg);
        }
    }

    public function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if (!($actual > $expected)) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual [%s] is greater than expected [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }

    public function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if (!($actual >= $expected)) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual [%s] is greater than or equal to expected [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }

    public function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if (!($actual < $expected)) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual [%s] is less than expected [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }

    public function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->recordAssertion();
        if (!($actual <= $expected)) {
            $msg = $message ?: sprintf(
                "Failed asserting that actual [%s] is less than or equal to expected [%s].",
                var_export($actual, true),
                var_export($expected, true)
            );
            $this->fail($msg);
        }
    }
}

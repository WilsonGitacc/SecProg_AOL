<?php

declare(strict_types=1);

namespace Tests\Support;

spl_autoload_register(function (string $class) {
    if (str_starts_with($class, 'Tests\\')) {
        $relative = substr($class, 6);
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    if (str_starts_with($class, 'App\\')) {
        $relative = substr($class, 4);
        $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

require_once __DIR__ . '/TestCase.php';

class TestRunner
{
    private string $testsDir;
    private ?string $filter = null;
    private int $totalTests = 0;
    private int $totalAssertions = 0;
    private array $failures = [];
    private float $startTime;

    public function __construct(string $testsDir, ?string $filter = null)
    {
        $this->testsDir = rtrim($testsDir, '/');
        $this->filter = $filter;
        $this->startTime = microtime(true);
    }

    public static function runCLI(array $argv): int
    {
        $filter = null;
        $suite = null;
        $testsDir = dirname(__DIR__);

        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--filter=')) {
                $filter = substr($arg, 9);
            } elseif (!str_starts_with($arg, '-')) {
                $suite = $arg;
            }
        }

        if ($suite) {
            $candidate = $testsDir . '/' . ltrim($suite, '/');
            if (is_dir($candidate) || is_file($candidate)) {
                $testsDir = $candidate;
            } else {
                $filter = $suite;
            }
        }

        $runner = new self($testsDir, $filter);
        return $runner->run();
    }

    public function run(): int
    {
        echo "\033[1;36m=== Secure Neo-Brutalist LMS Automated Test Suite ===\033[0m\n";
        echo "PHP Version: " . PHP_VERSION . " (" . PHP_SAPI . ")\n";
        echo "Root Test Directory: " . $this->testsDir . "\n";
        if ($this->filter) {
            echo "Active Filter: " . $this->filter . "\n";
        }
        echo str_repeat('-', 60) . "\n\n";

        $testFiles = $this->discoverTestFiles($this->testsDir);
        if (empty($testFiles)) {
            echo "\033[1;33mNo test files found matching criteria.\033[0m\n";
            return 0;
        }

        foreach ($testFiles as $file) {
            $this->runTestFile($file);
        }

        $duration = microtime(true) - $this->startTime;
        $this->printSummary($duration);

        return count($this->failures) === 0 ? 0 : 1;
    }

    private function discoverTestFiles(string $dir): array
    {
        if (is_file($dir)) {
            return [$dir];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), 'Test.php')) {
                $files[] = $item->getPathname();
            }
        }

        sort($files);
        return $files;
    }

    private function runTestFile(string $filePath): void
    {
        $declaredClassesBefore = get_declared_classes();
        require_once $filePath;
        $declaredClassesAfter = get_declared_classes();
        $newClasses = array_diff($declaredClassesAfter, $declaredClassesBefore);

        $testClasses = [];
        foreach ($newClasses as $class) {
            if (is_subclass_of($class, TestCase::class)) {
                $testClasses[] = $class;
            }
        }

        if (empty($testClasses)) {
            // Check if class name can be derived from filename
            $baseName = basename($filePath, '.php');
            foreach (get_declared_classes() as $class) {
                if (str_ends_with($class, '\\' . $baseName) && is_subclass_of($class, TestCase::class)) {
                    $testClasses[] = $class;
                    break;
                }
            }
        }

        foreach ($testClasses as $class) {
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract()) {
                continue;
            }

            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
            $shortClassName = $reflection->getShortName();

            $matchingMethods = [];
            foreach ($methods as $method) {
                $methodName = $method->getName();
                if (!str_starts_with($methodName, 'test')) {
                    continue;
                }

                if ($this->filter && !str_contains($shortClassName . '::' . $methodName, $this->filter)) {
                    continue;
                }

                $matchingMethods[] = $methodName;
            }

            if (empty($matchingMethods)) {
                continue;
            }

            echo sprintf("\033[1;34m[%s]\033[0m\n", $shortClassName);

            foreach ($matchingMethods as $methodName) {
                $this->executeSingleTest($class, $methodName);
            }
            echo "\n";
        }
    }

    private function executeSingleTest(string $className, string $methodName): void
    {
        $this->totalTests++;
        /** @var TestCase $testInstance */
        $testInstance = new $className();
        $failed = false;
        $errorMessage = '';
        $trace = '';

        try {
            $setUpMethod = new \ReflectionMethod($testInstance, 'setUp');
            $setUpMethod->invoke($testInstance);

            $testInstance->$methodName();

            // Check if an expected exception was NOT thrown
            if ($testInstance->getExpectedException() !== null) {
                throw new AssertionFailedError(
                    sprintf("Expected exception [%s] was not thrown.", $testInstance->getExpectedException())
                );
            }
        } catch (\Throwable $e) {
            $expectedClass = $testInstance->getExpectedException();
            $expectedMsg = $testInstance->getExpectedExceptionMessage();

            if ($expectedClass !== null && ($e instanceof $expectedClass)) {
                if ($expectedMsg === null || str_contains($e->getMessage(), $expectedMsg)) {
                    // Expected exception caught successfully
                    $testInstance->clearExpectedException();
                } else {
                    $failed = true;
                    $errorMessage = sprintf(
                        "Caught expected exception [%s], but message [%s] did not contain [%s].",
                        get_class($e),
                        $e->getMessage(),
                        $expectedMsg
                    );
                    $trace = $e->getTraceAsString();
                }
            } else {
                $failed = true;
                $errorMessage = sprintf("[%s] %s", get_class($e), $e->getMessage());
                $trace = $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
            }
        } finally {
            try {
                $tearDownMethod = new \ReflectionMethod($testInstance, 'tearDown');
                $tearDownMethod->invoke($testInstance);
            } catch (\Throwable $tearDownException) {
                if (!$failed) {
                    $failed = true;
                    $errorMessage = "tearDown error: " . $tearDownException->getMessage();
                    $trace = $tearDownException->getTraceAsString();
                }
            }
        }

        $this->totalAssertions += $testInstance->getAssertionCount();

        if ($failed) {
            echo "  \033[31m✘ {$methodName}\033[0m\n";
            $this->failures[] = [
                'test' => "{$className}::{$methodName}",
                'message' => $errorMessage,
                'trace' => $trace,
            ];
        } else {
            echo "  \033[32m✔ {$methodName}\033[0m\n";
        }
    }

    private function printSummary(float $duration): void
    {
        echo str_repeat('=', 60) . "\n";
        if (empty($this->failures)) {
            echo sprintf(
                "\033[1;32mSUCCESS: All %d test(s) passed (%d assertion(s)) in %.3fs\033[0m\n",
                $this->totalTests,
                $this->totalAssertions,
                $duration
            );
        } else {
            echo sprintf(
                "\033[1;31mFAILURE: %d of %d test(s) failed in %.3fs\033[0m\n\n",
                count($this->failures),
                $this->totalTests,
                $duration
            );

            echo "\033[1;31m--- Failure Details ---\033[0m\n";
            foreach ($this->failures as $i => $failure) {
                echo sprintf("\033[1;31m%d) %s\033[0m\n", $i + 1, $failure['test']);
                echo "   Error: " . $failure['message'] . "\n";
                if (!empty($failure['trace'])) {
                    $lines = explode("\n", $failure['trace']);
                    echo "   Location: " . ($lines[0] ?? '') . "\n";
                }
                echo "\n";
            }
        }
        echo str_repeat('=', 60) . "\n";
    }
}

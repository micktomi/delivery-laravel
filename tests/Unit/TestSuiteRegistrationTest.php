<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Deliberately a plain PHPUnit test: it reads the runner's own configuration
 * and never boots the application.
 *
 * tests/Integration holds the only tests that exercise real InnoDB row
 * locking - the checkout race and the terminal-order race, the latter
 * covering Viva start against cancellation. They were absent from
 * phpunit.xml, so they did not run and did not report as skipped either:
 * they simply were not there, and nothing said so. This keeps every test
 * directory accounted for by the runner.
 */
class TestSuiteRegistrationTest extends TestCase
{
    public function test_every_test_directory_belongs_to_a_registered_suite(): void
    {
        $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');

        $this->assertNotFalse($configuration, 'phpunit.xml could not be parsed.');

        $directories = [];
        foreach ($configuration->testsuites->testsuite as $suite) {
            foreach ($suite->directory as $directory) {
                $directories[] = trim((string) $directory);
            }
        }

        foreach (['tests/Unit', 'tests/Feature', 'tests/Integration'] as $directory) {
            $this->assertContains(
                $directory,
                $directories,
                $directory.' exists but no phpunit.xml testsuite runs it.',
            );
        }
    }
}

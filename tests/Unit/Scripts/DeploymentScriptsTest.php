<?php

namespace Tests\Unit\Scripts;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the deployment scripts (scripts/*.sh) against isolated temp
 * directories and a tiny fake `artisan` PHP stub standing in for a real
 * Laravel release — no composer/npm/Laravel boot involved, so this stays
 * fast. build-release.sh itself (composer/npm/git archive) is not run
 * here: it is a slow, network-dependent build step, verified manually
 * and via the release actually produced for this task instead of on
 * every test run.
 */
class DeploymentScriptsTest extends TestCase
{
    private string $scriptsDir;

    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->scriptsDir = dirname(__DIR__, 3).'/scripts';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }
        parent::tearDown();
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /** @return array{0: int, 1: string} [exit code, combined stdout+stderr] */
    private function runScript(string $script, array $args = [], array $env = []): array
    {
        $cmd = array_merge(['bash', $this->scriptsDir.'/'.$script], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));

        $envAssignments = '';
        foreach ($env as $key => $value) {
            $envAssignments .= $key.'='.escapeshellarg((string) $value).' ';
        }

        exec($envAssignments.$escaped.' 2>&1', $outputLines, $exitCode);

        return [$exitCode, implode("\n", $outputLines)];
    }

    // --- syntax -----------------------------------------------------------

    public function test_every_script_has_valid_bash_syntax(): void
    {
        $scripts = glob($this->scriptsDir.'/*.sh');
        $scripts = array_merge($scripts, glob($this->scriptsDir.'/lib/*.sh'));
        $this->assertNotEmpty($scripts);

        foreach ($scripts as $script) {
            exec('bash -n '.escapeshellarg($script).' 2>&1', $output, $code);
            $this->assertSame(0, $code, "$script has a syntax error: ".implode("\n", $output));
        }
    }

    // --- fixtures -----------------------------------------------------------

    private function writeEnvFile(string $path, string $instanceRoot, array $overrides = []): void
    {
        $values = array_merge([
            'APP_NAME' => 'Test Instance',
            'APP_ENV' => 'production',
            'APP_KEY' => 'base64:0000000000000000000000000000000000000000=',
            'APP_URL' => '',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $instanceRoot.'/shared/database/database.sqlite',
        ], $overrides);

        $lines = array_map(fn ($k, $v) => "$k=$v", array_keys($values), $values);
        file_put_contents($path, implode("\n", $lines)."\n");
    }

    /** Builds a fake release artifact around a PHP `artisan` stub. */
    private function buildFakeArtifact(string $dir, ?string $failingArgs = null): string
    {
        $src = $this->tempDir('fake-artisan-src');
        mkdir($src.'/storage');

        $failCheck = $failingArgs !== null
            ? 'if ($argsStr === '.var_export($failingArgs, true).") { fwrite(STDERR, \"boom\\n\"); exit(1); }"
            : '';

        file_put_contents($src.'/artisan', <<<PHP
            #!/usr/bin/env php
            <?php
            \$argsStr = implode(' ', array_slice(\$argv, 1));
            file_put_contents(__DIR__.'/calls.log', \$argsStr."\\n", FILE_APPEND);
            $failCheck
            exit(0);
            PHP);
        chmod($src.'/artisan', 0755);
        file_put_contents($src.'/marker.txt', 'release contents');

        $artifact = $dir.'/release.tar.gz';
        exec('tar -czf '.escapeshellarg($artifact).' -C '.escapeshellarg($src).' .', $o, $code);
        $this->assertSame(0, $code, 'failed to build fake artifact');

        return $artifact;
    }

    private function checksumFile(string $artifact): string
    {
        $sumFile = $artifact.'.sha256';
        exec('sha256sum '.escapeshellarg($artifact), $out);
        file_put_contents($sumFile, $out[0]."\n");

        return $sumFile;
    }

    private function provision(string $instanceRoot, ?string $envFile = null): array
    {
        $envFile ??= $instanceRoot.'.env';
        if (! is_file($envFile)) {
            $this->writeEnvFile($envFile, $instanceRoot);
        }

        return $this->runScript('provision-instance.sh', ['--instance-root', $instanceRoot, '--env-file', $envFile]);
    }

    // --- provision ------------------------------------------------------------

    public function test_provision_creates_the_expected_skeleton(): void
    {
        $root = $this->tempDir('inst').'/instance';
        [$code, $out] = $this->provision($root);

        $this->assertSame(0, $code, $out);
        $this->assertDirectoryExists($root.'/releases');
        $this->assertDirectoryExists($root.'/shared/storage/app/public');
        $this->assertDirectoryExists($root.'/shared/backups');
        $this->assertFileExists($root.'/shared/.env');
        $this->assertFileExists($root.'/shared/database/database.sqlite');
    }

    public function test_provision_refuses_a_non_empty_existing_instance_root(): void
    {
        $root = $this->tempDir('inst').'/instance';
        $this->provision($root);

        [$code, $out] = $this->provision($root);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('refusing to overwrite', $out);
    }

    public function test_provision_rejects_an_empty_app_key(): void
    {
        $root = $this->tempDir('inst').'/instance';
        $envFile = $root.'.env';
        $this->writeEnvFile($envFile, $root, ['APP_KEY' => '']);

        [$code, $out] = $this->provision($root, $envFile);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('APP_KEY', $out);
        $this->assertDirectoryDoesNotExist($root);
    }

    // --- deploy -----------------------------------------------------------

    public function test_deploy_creates_a_release_dir_links_shared_paths_and_switches_current(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/instance';
        $this->provision($root);
        $artifact = $this->buildFakeArtifact($dir);

        [$code, $out] = $this->runScript('deploy-instance.sh', [
            '--instance-root', $root, '--artifact', $artifact, '--release-id', 'r1',
        ]);

        $this->assertSame(0, $code, $out);
        $this->assertDirectoryExists($root.'/releases/r1');
        $this->assertFileExists($root.'/releases/r1/marker.txt');
        $this->assertTrue(is_link($root.'/current'));
        $this->assertSame(realpath($root.'/releases/r1'), realpath($root.'/current'));

        // Shared paths resolve from inside the release.
        $this->assertTrue(is_link($root.'/releases/r1/storage'));
        $this->assertSame(realpath($root.'/shared/storage'), realpath($root.'/releases/r1/storage'));
        $this->assertTrue(is_link($root.'/releases/r1/.env'));
        $this->assertSame(realpath($root.'/shared/.env'), realpath($root.'/releases/r1/.env'));

        // A backup was taken before migrate ran.
        $backups = glob($root.'/shared/backups/pre-deploy-*.sqlite');
        $this->assertNotEmpty($backups);

        $calls = file_get_contents($root.'/current/calls.log');
        $this->assertStringContainsString('migrate --force', $calls);
        $this->assertStringContainsString('storage:link', $calls);
        $this->assertStringContainsString('config:cache', $calls);
    }

    public function test_deploy_refuses_to_overwrite_an_existing_release_directory(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/instance';
        $this->provision($root);
        $artifact = $this->buildFakeArtifact($dir);

        $this->runScript('deploy-instance.sh', ['--instance-root', $root, '--artifact', $artifact, '--release-id', 'dup']);
        [$code, $out] = $this->runScript('deploy-instance.sh', ['--instance-root', $root, '--artifact', $artifact, '--release-id', 'dup']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('refusing to overwrite', $out);
    }

    public function test_deploy_fails_on_checksum_mismatch_and_does_not_touch_current(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/instance';
        $this->provision($root);
        $artifact = $this->buildFakeArtifact($dir);
        $badSum = $dir.'/bad.sha256';
        file_put_contents($badSum, str_repeat('0', 64)."  release.tar.gz\n");

        [$code, $out] = $this->runScript('deploy-instance.sh', [
            '--instance-root', $root, '--artifact', $artifact, '--checksum-file', $badSum,
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('checksum mismatch', $out);
        $this->assertFileDoesNotExist($root.'/current');
    }

    public function test_deploy_accepts_a_matching_checksum(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/instance';
        $this->provision($root);
        $artifact = $this->buildFakeArtifact($dir);
        $goodSum = $this->checksumFile($artifact);

        [$code, $out] = $this->runScript('deploy-instance.sh', [
            '--instance-root', $root, '--artifact', $artifact, '--checksum-file', $goodSum,
        ]);

        $this->assertSame(0, $code, $out);
    }

    public function test_failed_post_switch_health_check_reverts_current_to_the_previous_release(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/instance';
        $this->provision($root);

        $good = $this->buildFakeArtifact($this->tempDir('good-artifact'));
        $this->runScript('deploy-instance.sh', ['--instance-root', $root, '--artifact', $good, '--release-id', 'good']);
        $this->assertSame(realpath($root.'/releases/good'), realpath($root.'/current'));

        $bad = $this->buildFakeArtifact($this->tempDir('bad-artifact'), failingArgs: 'about --only=environment');
        [$code, $out] = $this->runScript('deploy-instance.sh', ['--instance-root', $root, '--artifact', $bad, '--release-id', 'bad']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('HEALTH CHECK FAILED', $out);
        $this->assertSame(realpath($root.'/releases/good'), realpath($root.'/current'), 'current should have reverted to the previous release');
        $this->assertDirectoryExists($root.'/releases/bad', 'the failed candidate should be kept for inspection');
    }

    public function test_failed_pre_switch_check_never_touches_current_and_cleans_up_the_candidate(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/instance';
        $this->provision($root);

        $bad = $this->buildFakeArtifact($dir, failingArgs: 'about --only=environment,drivers');
        [$code, $out] = $this->runScript('deploy-instance.sh', ['--instance-root', $root, '--artifact', $bad, '--release-id', 'bad']);

        $this->assertNotSame(0, $code);
        $this->assertFileDoesNotExist($root.'/current');
        $this->assertDirectoryDoesNotExist($root.'/releases/bad', 'an incomplete candidate that never reached the switch should be removed');
    }

    public function test_deploy_does_not_touch_a_pre_existing_instance_database(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/instance';
        $this->provision($root);
        $dbPath = $root.'/shared/database/database.sqlite';
        exec('sqlite3 '.escapeshellarg($dbPath)." \"CREATE TABLE orders(id INTEGER PRIMARY KEY, v TEXT); INSERT INTO orders(v) VALUES('real-order');\"");

        $artifact = $this->buildFakeArtifact($dir);
        [$code] = $this->runScript('deploy-instance.sh', ['--instance-root', $root, '--artifact', $artifact]);
        $this->assertSame(0, $code);

        exec('sqlite3 '.escapeshellarg($dbPath).' "SELECT v FROM orders;"', $rows);
        $this->assertSame(['real-order'], $rows);
    }

    public function test_deploy_requires_an_already_provisioned_instance(): void
    {
        $dir = $this->tempDir('deploy');
        $root = $dir.'/never-provisioned';
        $artifact = $this->buildFakeArtifact($dir);

        [$code, $out] = $this->runScript('deploy-instance.sh', ['--instance-root', $root, '--artifact', $artifact]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('does not exist', $out);
    }

    public function test_deploy_rejects_a_relative_instance_root(): void
    {
        $dir = $this->tempDir('deploy');
        $artifact = $this->buildFakeArtifact($dir);

        [$code, $out] = $this->runScript('deploy-instance.sh', ['--instance-root', 'relative/path', '--artifact', $artifact]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('absolute', $out);
    }

    // --- restore ------------------------------------------------------------

    public function test_restore_refuses_without_explicit_confirmation(): void
    {
        $dir = $this->tempDir('restore');
        $root = $dir.'/instance';
        $this->provision($root);
        $backup = $dir.'/backup.sqlite';
        exec('sqlite3 '.escapeshellarg($backup)." \"CREATE TABLE t(id INTEGER);\"");

        [$code, $out] = $this->runScript('restore-instance.sh', ['--instance-root', $root, '--backup-file', $backup]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('--yes', $out);
    }

    public function test_restore_replaces_the_database_and_keeps_a_safety_copy(): void
    {
        $dir = $this->tempDir('restore');
        $root = $dir.'/instance';
        $this->provision($root);
        $dbPath = $root.'/shared/database/database.sqlite';
        exec('sqlite3 '.escapeshellarg($dbPath)." \"CREATE TABLE t(v TEXT); INSERT INTO t VALUES('live');\"");

        $backup = $dir.'/backup.sqlite';
        exec('sqlite3 '.escapeshellarg($backup)." \"CREATE TABLE t(v TEXT); INSERT INTO t VALUES('from-backup');\"");

        [$code, $out] = $this->runScript('restore-instance.sh', ['--instance-root', $root, '--backup-file', $backup, '--yes']);

        $this->assertSame(0, $code, $out);
        exec('sqlite3 '.escapeshellarg($dbPath).' "SELECT v FROM t;"', $rows);
        $this->assertSame(['from-backup'], $rows);

        $safetyCopies = glob($root.'/shared/backups/pre-restore-*.sqlite');
        $this->assertNotEmpty($safetyCopies, 'a safety copy of the pre-restore database should exist');
        exec('sqlite3 '.escapeshellarg($safetyCopies[0]).' "SELECT v FROM t;"', $safetyRows);
        $this->assertSame(['live'], $safetyRows, 'the safety copy should hold the data that was live just before the restore');
    }

    public function test_restore_requires_an_existing_backup_file(): void
    {
        $dir = $this->tempDir('restore');
        $root = $dir.'/instance';
        $this->provision($root);

        [$code, $out] = $this->runScript('restore-instance.sh', [
            '--instance-root', $root, '--backup-file', $dir.'/does-not-exist.sqlite', '--yes',
        ]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('backup file', $out);
    }

    public function test_restore_rejects_a_corrupt_backup_file(): void
    {
        $dir = $this->tempDir('restore');
        $root = $dir.'/instance';
        $this->provision($root);
        $backup = $dir.'/backup.sqlite';
        file_put_contents($backup, 'not a sqlite database');

        [$code, $out] = $this->runScript('restore-instance.sh', ['--instance-root', $root, '--backup-file', $backup, '--yes']);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('integrity_check', $out);
    }
}

<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Infrastructure\Git;

use EasyDcimBandwidthGuard\Support\Logger;
use WHMCS\Database\Capsule;

final class GitUpdateManager
{
    private string $repoRoot;
    private Logger $logger;

    public function __construct(string $moduleDir, Logger $logger)
    {
        $this->repoRoot = realpath($moduleDir . '/../../..') ?: $moduleDir;
        $this->logger = $logger;
    }

    public function checkForUpdate(string $originUrl, string $branch): array
    {
        $local = $this->runGit('rev-parse HEAD');
        $remoteLine = $this->runGit('ls-remote ' . escapeshellarg($originUrl) . ' ' . escapeshellarg($branch));

        $remote = '';
        if ($remoteLine) {
            $parts = preg_split('/\s+/', trim($remoteLine));
            $remote = $parts[0] ?? '';
        }

        $available = $local !== '' && $remote !== '' && $local !== $remote;
        Capsule::table('mod_easydcim_bw_update_log')->insert([
            'current_sha' => $local ?: null,
            'remote_sha' => $remote ?: null,
            'status' => $available ? 'update_available' : 'up_to_date',
            'details_json' => json_encode(['branch' => $branch, 'origin' => $originUrl], JSON_UNESCAPED_SLASHES),
            'checked_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Capsule::table('mod_easydcim_bw_meta')->updateOrInsert(
            ['meta_key' => 'update_available'],
            ['meta_value' => $available ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s')]
        );

        return ['available' => $available, 'local_sha' => $local, 'remote_sha' => $remote];
    }

    public function applyOneClickUpdate(string $originUrl, string $branch): array
    {
        $this->assertPreflight();
        $this->acquireUpdateLock();

        try {
            $this->runGit('remote set-url origin ' . escapeshellarg($originUrl));
            $fetch = $this->runGitWithRetry('fetch origin ' . escapeshellarg($branch), 2);
            $pull = $this->runGitWithRetry('pull --ff-only origin ' . escapeshellarg($branch), 2);
            $newSha = $this->runGit('rev-parse HEAD');

            Capsule::table('mod_easydcim_bw_update_log')->insert([
                'current_sha' => $newSha ?: null,
                'remote_sha' => $newSha ?: null,
                'status' => 'applied',
                'details_json' => json_encode(['fetch' => $fetch, 'pull' => $pull], JSON_UNESCAPED_SLASHES),
                'checked_at' => date('Y-m-d H:i:s'),
                'applied_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $this->logger->log('INFO', 'git_update_applied', ['sha' => $newSha]);

            return ['sha' => $newSha, 'fetch' => $fetch, 'pull' => $pull];
        } finally {
            $this->releaseUpdateLock();
        }
    }

    public function assertPreflight(): void
    {
        if (!is_writable($this->repoRoot . '/.git')) {
            throw new \RuntimeException('.git is not writable');
        }

        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            throw new \RuntimeException('PHP 8.0+ is required');
        }

        try {
            Capsule::connection()->select('SELECT 1');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Database check failed: ' . $e->getMessage());
        }
    }

    private function runGit(string $command): string
    {
        if (!function_exists('shell_exec')) {
            throw new \RuntimeException('shell_exec is disabled for git update manager');
        }

        $cmd = 'git -C ' . escapeshellarg($this->repoRoot) . ' ' . $command . ' 2>&1';
        $output = shell_exec($cmd);
        return trim((string) $output);
    }

    private function runGitWithRetry(string $command, int $retries): string
    {
        $result = '';
        for ($i = 0; $i <= $retries; $i++) {
            $result = $this->runGit($command);
            if (stripos($result, 'fatal:') === false && stripos($result, 'error:') === false) {
                return $result;
            }
            usleep(300000);
        }
        return $result;
    }

    private function acquireUpdateLock(): void
    {
        $locked = Capsule::table('mod_easydcim_bw_meta')->where('meta_key', 'update_in_progress')->value('meta_value');
        if ($locked === '1') {
            throw new \RuntimeException('Update already in progress');
        }

        Capsule::table('mod_easydcim_bw_meta')->updateOrInsert(
            ['meta_key' => 'update_in_progress'],
            ['meta_value' => '1', 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function releaseUpdateLock(): void
    {
        Capsule::table('mod_easydcim_bw_meta')->updateOrInsert(
            ['meta_key' => 'update_in_progress'],
            ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
        );
    }
}

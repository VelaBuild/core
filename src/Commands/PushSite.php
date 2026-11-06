<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\SitePack\SitePacker;

/**
 * Pack this site and import it into a Vela Hosted site in one step.
 *
 *   php artisan vela:push --token=vimp_…     first time: the token from the hosting
 *                                            panel (your site → Import); it is saved
 *   php artisan vela:push                    again later: replaces the hosted site
 *                                            with this one
 *
 * The hosted site's content is replaced (a backup is taken first, and the
 * import is undone automatically if the site doesn't come up).
 */
class PushSite extends Command
{
    protected $signature = 'vela:push
        {--token= : Import token from the Vela Hosted panel (saved for next time)}
        {--url= : Control panel address (default https://vela.build)}
        {--archive= : Upload this vela:pack archive instead of packing now}
        {--no-wait : Don\'t wait for the import to finish}
        {--force : Don\'t ask before replacing the hosted site}';

    protected $description = 'Pack this site and import it into Vela Hosted (replaces the hosted site\'s content)';

    private const SAVED = 'app/vela-push.json';

    public function handle(): int
    {
        $saved = json_decode((string) @file_get_contents(storage_path(self::SAVED)), true) ?: [];
        $token = (string) ($this->option('token') ?: ($saved['token'] ?? ''));
        $base = rtrim((string) ($this->option('url') ?: ($saved['url'] ?? 'https://vela.build')), '/');
        if (!preg_match('/^vimp_[A-Za-z0-9]{20,80}$/', $token)) {
            $this->error('Pass --token=… : create one in the Vela Hosted panel under your site → Import.');

            return self::FAILURE;
        }
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?$#', $base) && !app()->environment('local', 'testing')) {
            $this->error('--url must be an https:// address.');

            return self::FAILURE;
        }

        $http = Http::withToken($token)->acceptJson()->timeout(120)->baseUrl($base . '/api/hosting');
        $site = $http->get('imports/site');
        if (!$site->successful()) {
            $this->error($site->status() === 401 ? 'That token is not valid any more. Create a new one in the panel.' : 'Vela Hosted said: ' . $this->problem($site));

            return self::FAILURE;
        }
        if ($this->option('token')) {
            @mkdir(dirname(storage_path(self::SAVED)), 0755, true);
            file_put_contents(storage_path(self::SAVED), json_encode(['url' => $base, 'token' => $token]));
            @chmod(storage_path(self::SAVED), 0600);
        }
        $target = $site->json('site');
        $this->line("Target: <info>{$target['name']}</info> (https://{$target['domain']})");
        if (!$this->option('force') && !$this->confirm('This replaces everything on that site with this one. Continue?', true)) {
            return self::FAILURE;
        }

        $archive = $this->option('archive');
        $temporary = false;
        if (!$archive) {
            $packer = new SitePacker();
            if ($blockers = $packer->blockers()) {
                array_map(fn ($b) => $this->error($b), $blockers);

                return self::FAILURE;
            }
            $archive = storage_path('app/vela-push-' . gmdate('Ymd-His') . '.tar.gz');
            $temporary = true;
            $packer->pack($archive, fn ($step) => $this->line("· {$step}"));
        }

        try {
            $id = $this->upload($http, $archive);
        } finally {
            if ($temporary) {
                @unlink($archive);
            }
        }
        if ($id === null) {
            return self::FAILURE;
        }
        if ($this->option('no-wait')) {
            $this->info('Uploaded. The import runs on our side; the panel shows when it is done.');

            return self::SUCCESS;
        }

        return $this->wait($http, $id, $target);
    }

    private function upload(PendingRequest $http, string $archive): ?int
    {
        $bytes = filesize($archive);
        $start = $http->post('imports', [
            'bytes'    => $bytes,
            'sha256'   => hash_file('sha256', $archive),
            'filename' => basename($archive),
        ]);
        if (!$start->successful()) {
            $this->error('Upload refused: ' . $this->problem($start));

            return null;
        }
        $id = (int) $start->json('id');
        $chunk = (int) $start->json('chunk_bytes');
        $this->line('· Uploading ' . PackSite::size($bytes));
        $bar = $this->output->createProgressBar($bytes);
        $in = fopen($archive, 'rb');
        for ($offset = 0; $offset < $bytes; $offset += $chunk) {
            $body = fread($in, $chunk);
            $sent = null;
            foreach ([1, 2, 3] as $attempt) {
                $sent = $http->withBody($body, 'application/octet-stream')->put("imports/{$id}/chunk?offset={$offset}");
                if ($sent->successful()) {
                    break;
                }
                sleep($attempt * 2);
            }
            if (!$sent->successful()) {
                fclose($in);
                $this->newLine();
                $this->error('Upload failed: ' . $this->problem($sent));

                return null;
            }
            $bar->advance(strlen($body));
        }
        fclose($in);
        $bar->finish();
        $this->newLine();
        $done = $http->post("imports/{$id}/complete");
        if (!$done->successful()) {
            $this->error('Upload not accepted: ' . $this->problem($done));

            return null;
        }

        return $id;
    }

    private function wait(PendingRequest $http, int $id, array $target): int
    {
        $this->line('· Importing on Vela Hosted (this takes a minute or two)');
        $deadline = time() + 45 * 60;
        while (time() < $deadline) {
            sleep(5);
            $status = $http->get("imports/{$id}");
            if (!$status->successful()) {
                continue;
            }
            if ($status->json('status') === 'done') {
                $this->info("Done: https://{$target['domain']} is now this site. Sign in at /vela/login with this site's own admin login.");

                return self::SUCCESS;
            }
            if ($status->json('status') === 'failed') {
                $this->error('The import did not finish, and the hosted site was left as it was: ' . $status->json('error'));

                return self::FAILURE;
            }
        }
        $this->warn('Still importing. Check the panel for the result.');

        return self::SUCCESS;
    }

    private function problem($response): string
    {
        return (string) ($response->json('message') ?: $response->json('error') ?: 'HTTP ' . $response->status());
    }
}

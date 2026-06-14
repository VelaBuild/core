<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class EmailPreviewTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $action = $parameters['action'] ?? 'list';

        return match ($action) {
            'list' => $this->listMailables(),
            'preview' => $this->previewMailable($parameters),
            'test_send' => $this->testSend($parameters),
            default => ['error' => "Unknown action: {$action}. Available: list, preview, test_send"],
        };
    }

    private function listMailables(): array
    {
        $mailDir = app_path('Mail');
        $velaMailDir = base_path('vendor/velabuild/core/src/Mail');

        $mailables = [];

        foreach ([$mailDir, $velaMailDir] as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '/*.php') as $file) {
                $class = pathinfo($file, PATHINFO_FILENAME);
                $mailables[] = [
                    'class' => $class,
                    'path' => str_replace(base_path() . '/', '', $file),
                ];
            }
        }

        $views = [];
        foreach (['resources/views/emails', 'resources/views/vendor/vela/emails'] as $viewDir) {
            $full = base_path($viewDir);
            if (!is_dir($full)) continue;
            foreach (glob($full . '/*.blade.php') as $file) {
                $views[] = str_replace(base_path() . '/', '', $file);
            }
        }

        return ['mailables' => $mailables, 'email_views' => $views];
    }

    private function previewMailable(array $params): array
    {
        $view = $params['view'] ?? '';
        if (!$view) return ['error' => 'view is required (e.g. emails.welcome)'];

        try {
            $html = view($view, $params['data'] ?? [])->render();
            return ['html' => mb_substr($html, 0, 50_000), 'view' => $view];
        } catch (\Throwable $e) {
            return ['error' => 'Failed to render: ' . $e->getMessage()];
        }
    }

    private function testSend(array $params): array
    {
        $to = $params['to'] ?? '';
        $subject = $params['subject'] ?? 'Test email from Vela';
        $body = $params['body'] ?? 'This is a test email sent from the Vela AI assistant.';

        if (!$to) return ['error' => 'to (email address) is required'];
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['error' => 'Invalid email address'];

        if (!($params['confirm'] ?? false)) {
            return [
                'requires_confirmation' => true,
                'message' => "Send test email to {$to}? Call again with confirm: true.",
            ];
        }

        try {
            \Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
            return ['success' => true, 'sent_to' => $to];
        } catch (\Throwable $e) {
            return ['error' => 'Send failed: ' . $e->getMessage()];
        }
    }
}

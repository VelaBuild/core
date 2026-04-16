<?php

namespace VelaBuild\Core\Http\Controllers\Admin\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;

class EmailTesterController extends Controller
{
    public function index()
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $mailConfig = [
            'driver' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'encryption' => config('mail.mailers.smtp.encryption'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];

        return view('vela::admin.tools.email-tester', [
            'mailConfig' => $mailConfig,
        ]);
    }

    public function send(Request $request)
    {
        abort_if(Gate::none(['tools_access', 'admin_tools_access']), Response::HTTP_FORBIDDEN);

        $request->validate([
            'to' => 'required|email',
            'subject' => 'nullable|string|max:255',
        ]);

        // Rate limit: 5 sends per hour per user
        $userId = auth('vela')->id();
        $key = "email_tester:{$userId}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'success' => false,
                'message' => __('vela::tools.email.rate_limit_exceeded'),
            ], 429);
        }

        RateLimiter::hit($key, 3600);

        $to = $request->input('to');
        $subject = $request->input('subject', 'Vela CMS — Test Email');

        // Check if mail driver is configured
        if (!config('mail.default') || config('mail.default') === 'log') {
            return response()->json([
                'success' => false,
                'message' => __('vela::tools.email.no_driver_configured'),
                'diagnostics' => ['driver' => config('mail.default')],
            ]);
        }

        try {
            $startTime = microtime(true);

            Mail::raw("This is a test email from Vela CMS.\n\nSent at: " . now()->toDateTimeString(), function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });

            $elapsed = round((microtime(true) - $startTime) * 1000);

            Log::info('Email test sent', ['to' => $to, 'user' => $userId]);

            return response()->json([
                'success' => true,
                'message' => __('vela::tools.email.test_sent', ['to' => $to]),
                'diagnostics' => [
                    'driver' => config('mail.default'),
                    'elapsed_ms' => $elapsed,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Email test failed', ['to' => $to, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => __('vela::tools.email.send_failed', ['error' => $e->getMessage()]),
                'diagnostics' => [
                    'driver' => config('mail.default'),
                    'error' => $e->getMessage(),
                ],
            ]);
        }
    }
}

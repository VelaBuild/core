<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Jobs\ProcessAiChatMessageJob;
use Gate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AiChatController extends Controller
{
    /**
     * POST /admin/ai-chat/message
     * Submit a user message. Dispatches async job or runs synchronously if queue=sync.
     */
    public function message(Request $request)
    {
        abort_if(Gate::denies('ai_chat_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $request->validate([
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|integer|exists:vela_ai_conversations,id',
            'page_context' => 'nullable|array',
        ]);

        $user = auth('vela')->user();

        // Rate limiting check
        $recentCount = AiMessage::whereHas('conversation', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->where('role', 'user')
          ->where('created_at', '>=', now()->subHour())
          ->count();

        if ($recentCount >= config('vela.ai.chat.rate_limit', 50)) {
            return response()->json([
                'success' => false,
                'message' => 'Rate limit exceeded. Please wait before sending more messages.'
            ], 429);
        }

        // Create or retrieve conversation
        $conversation = $request->conversation_id
            ? AiConversation::where('user_id', $user->id)->findOrFail($request->conversation_id)
            : AiConversation::create([
                'user_id' => $user->id,
                'title' => \Str::limit($request->message, 50),
                'context' => $request->page_context,
            ]);

        // Save user message
        $userMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->message,
        ]);

        // Check if running sync queue
        $isSync = config('queue.default') === 'sync';

        if ($isSync) {
            // Run synchronously -- process inline and return response
            $job = new ProcessAiChatMessageJob($conversation->id, $user->id, $request->page_context ?? []);
            $job->handle();

            // Fetch the assistant response
            $newMessages = AiMessage::where('conversation_id', $conversation->id)
                ->where('id', '>', $userMessage->id)
                ->get();

            return response()->json([
                'success' => true,
                'conversation_id' => $conversation->id,
                'messages' => $newMessages,
                'status' => 'completed',
            ]);
        }

        // Async: dispatch job and return immediately
        ProcessAiChatMessageJob::dispatch($conversation->id, $user->id, $request->page_context ?? []);

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'message_id' => $userMessage->id,
            'status' => 'processing',
        ]);
    }

    /**
     * GET /admin/ai-chat/poll/{conversation}
     * Return new messages since a given message ID.
     */
    public function poll(Request $request, AiConversation $conversation)
    {
        abort_if(Gate::denies('ai_chat_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        // Ensure user owns this conversation
        $user = auth('vela')->user();
        if ($conversation->user_id !== $user->id) {
            abort(403);
        }

        $afterId = $request->query('after', 0);
        $messages = AiMessage::where('conversation_id', $conversation->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->get();

        // Get action logs for these messages (for undo buttons)
        $messageIds = $messages->pluck('id')->toArray();
        $actionLogs = AiActionLog::where('conversation_id', $conversation->id)
            ->whereIn('message_id', $messageIds)
            ->where('status', 'completed')
            ->whereNull('undone_at')
            ->get();

        return response()->json([
            'success' => true,
            'messages' => $messages,
            'action_logs' => $actionLogs,
            'status' => $messages->isEmpty() ? 'processing' : 'completed',
        ]);
    }

    /**
     * POST /admin/ai-chat/undo/{actionLog}
     * Undo a specific action.
     */
    public function undo(Request $request, AiActionLog $actionLog)
    {
        abort_if(Gate::denies('ai_chat_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $user = auth('vela')->user();
        if ($actionLog->user_id !== $user->id) {
            abort(403);
        }

        if (!$actionLog->canUndo()) {
            return response()->json([
                'success' => false,
                'message' => 'This action cannot be undone.'
            ], 400);
        }

        try {
            $executor = app(ChatToolExecutor::class);
            $executor->undoAction($actionLog);

            return response()->json([
                'success' => true,
                'message' => "Undone: {$actionLog->tool_name}"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Undo failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /admin/ai-chat/conversations
     * List user's conversations.
     */
    public function conversations(Request $request)
    {
        abort_if(Gate::denies('ai_chat_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $user = auth('vela')->user();
        $conversations = AiConversation::where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->take(20)
            ->get(['id', 'title', 'created_at', 'updated_at']);

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }

    /**
     * GET /admin/ai-chat/history/{conversation}
     * Get full conversation history.
     */
    public function history(AiConversation $conversation)
    {
        abort_if(Gate::denies('ai_chat_access'), Response::HTTP_FORBIDDEN, '403 Forbidden');

        $user = auth('vela')->user();
        if ($conversation->user_id !== $user->id) {
            abort(403);
        }

        $messages = $conversation->messages()->get();
        $actionLogs = $conversation->actionLogs()
            ->where('status', 'completed')
            ->whereNull('undone_at')
            ->get();

        return response()->json([
            'success' => true,
            'conversation' => $conversation,
            'messages' => $messages,
            'action_logs' => $actionLogs,
        ]);
    }
}

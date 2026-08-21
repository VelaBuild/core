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
use Illuminate\Support\Str;
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
            'image' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
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

        // Handle image upload for vision
        $imageData = null;
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $imageData = [
                'base64' => base64_encode(file_get_contents($file->getPathname())),
                'media_type' => $file->getMimeType(),
                'filename' => $file->getClientOriginalName(),
            ];
        }

        // Save user message
        $userMessage = AiMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $request->message,
            'metadata' => $imageData ? ['image' => $imageData] : null,
        ]);

        // Always return fast so the client (and any CDN proxy with a 30s
        // hard cap like Cloudflare) doesn't time out on long tool chains.
        // dispatchAfterResponse runs the job after the HTTP response is
        // flushed even on a sync queue — frontend then polls for results.
        ProcessAiChatMessageJob::dispatchAfterResponse(
            $conversation->id,
            $user->id,
            $request->page_context ?? [],
            $userMessage->id,
            $request->message,
            $imageData
        );

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

        // "Completed" means the assistant produced a FINAL reply — content
        // present and no further tool_calls pending. Intermediate messages
        // (assistant with tool_calls, tool results) keep status=processing so
        // the frontend keeps polling instead of giving up + showing an error.
        $last = $messages->last();
        $isFinal = $last
            && $last->role === 'assistant'
            && !empty($last->content)
            && empty($last->tool_calls);

        return response()->json([
            'success' => true,
            'messages' => $messages,
            'action_logs' => $actionLogs,
            'status' => $isFinal ? 'completed' : 'processing',
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
            ->withCount([
                'messages',
                'actionLogs as edit_count' => function ($query) {
                    $query->where('status', 'completed')->whereNull('undone_at');
                },
            ])
            ->orderBy('updated_at', 'desc')
            ->take(20)
            ->get(['id', 'title', 'created_at', 'updated_at']);

        // Every conversation is titled by the same generator, so a list of
        // titles alone reads as twenty copies of one entry. The first thing
        // the user actually asked for is what tells them apart.
        $transcripts = AiMessage::whereIn('conversation_id', $conversations->pluck('id'))
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('id')
            ->get(['conversation_id', 'role', 'content'])
            ->groupBy('conversation_id');

        $openings = $transcripts->map(
            fn ($messages) => (string) ($messages->firstWhere('role', 'user')->content ?? '')
        );

        // The last thing the assistant said is where a conversation was left,
        // which is what the user is looking for when they come back to it —
        // and unlike the opening it does not just repeat the title.
        $endings = $transcripts->map(
            fn ($messages) => (string) ($messages->last(fn ($message) => $message->role === 'assistant')->content ?? '')
        );

        return response()->json([
            'success' => true,
            'conversations' => $conversations->map(function (AiConversation $conversation) use ($openings, $endings) {
                $opening = trim(preg_replace('/\s+/', ' ', $openings[$conversation->id] ?? ''));
                // Markdown scaffolding reads as noise at preview length.
                $ending = trim(preg_replace(
                    ['/```.*?```/s', '/[*_`#>]+/', '/\s+/'],
                    ['', '', ' '],
                    $endings[$conversation->id] ?? ''
                ));
                $title = $conversation->title ?: (Str::limit($opening, 48) ?: 'Conversation #' . $conversation->id);

                // Titles are generated from the opening question, so showing the
                // opening underneath repeats it. Where the assistant replied,
                // its last answer says where the conversation was left instead.
                $preview = $ending !== '' ? $ending : $opening;

                return [
                    'id' => $conversation->id,
                    'title' => $title,
                    'preview' => Str::limit($preview, 110),
                    'message_count' => $conversation->messages_count,
                    'edit_count' => $conversation->edit_count,
                    'updated_at' => $conversation->updated_at?->toIso8601String(),
                    'updated_human' => $conversation->updated_at?->diffForHumans(),
                    'started_human' => $conversation->created_at?->isoFormat('D MMM YYYY'),
                ];
            }),
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

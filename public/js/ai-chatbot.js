(function() {
    'use strict';

    var config = window._aiChatConfig;
    if (!config) return;

    var conversationId = null;
    var lastMessageId = 0;
    var pollInterval = null;
    var pollTimeout = null;
    var lastActionLogId = null;
    var isLoading = false;

    // -----------------------------------------------------------------------
    // Sidebar toggle
    // -----------------------------------------------------------------------

    function toggleChatSidebar() {
        var sidebar = document.getElementById('ai-chatbot-sidebar');
        var isVisible = sidebar.style.display !== 'none';
        sidebar.style.display = isVisible ? 'none' : 'flex';
        localStorage.setItem('ai-chat-open', isVisible ? '0' : '1');
    }

    var toggleBtn = document.getElementById('ai-chat-toggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleChatSidebar);
    }


    document.getElementById('ai-chat-close').addEventListener('click', function() {
        document.getElementById('ai-chatbot-sidebar').style.display = 'none';
        localStorage.setItem('ai-chat-open', '0');
    });

    // -----------------------------------------------------------------------
    // Restore state on page load
    // -----------------------------------------------------------------------

    if (localStorage.getItem('ai-chat-open') === '1') {
        var sidebar = document.getElementById('ai-chatbot-sidebar');
        if (sidebar) sidebar.style.display = 'flex';

        var savedConvId = localStorage.getItem('ai-chat-conversation-id');
        if (savedConvId) {
            conversationId = parseInt(savedConvId, 10);
            loadConversationHistory(conversationId);
        }
    }

    // -----------------------------------------------------------------------
    // Send message
    // -----------------------------------------------------------------------

    function sendMessage() {
        var input = document.getElementById('ai-chat-input');
        var message = input.value.trim();
        if (!message || isLoading) return;

        input.value = '';
        appendMessage('user', message);
        showLoading();

        var pageContext = {
            url: window.location.href,
            route: window.location.pathname
        };

        fetch(config.routes.message, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken
            },
            body: JSON.stringify({
                message: message,
                conversation_id: conversationId,
                page_context: pageContext
            })
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                conversationId = data.conversation_id;
                localStorage.setItem('ai-chat-conversation-id', conversationId);

                if (data.status === 'completed' && data.messages) {
                    hideLoading();
                    var hasContent = false;
                    data.messages.forEach(function(msg) {
                        if (msg.role === 'assistant' && msg.content) {
                            appendMessage('assistant', msg.content, msg.tool_calls);
                            hasContent = true;
                        }
                    });
                    if (!hasContent) {
                        appendMessage('assistant', 'Sorry, I encountered an error processing your request. Please try again.');
                    }
                    if (data.action_logs && data.action_logs.length > 0) {
                        var lastLog = data.action_logs[data.action_logs.length - 1];
                        showUndoBar(lastLog.id, lastLog.tool_name);
                    }
                } else {
                    lastMessageId = data.message_id || 0;
                    startPolling();
                }
            } else {
                hideLoading();
                appendMessage('assistant', 'Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function() {
            hideLoading();
            appendMessage('assistant', 'Connection error. Please try again.');
        });
    }

    // -----------------------------------------------------------------------
    // Polling
    // -----------------------------------------------------------------------

    function startPolling() {
        stopPolling();

        var elapsed = 0;
        var maxWait = 60000;
        var interval = 1500;

        pollInterval = setInterval(function() {
            elapsed += interval;
            if (elapsed >= maxWait) {
                stopPolling();
                hideLoading();
                appendMessage('assistant', 'Request timed out. Please try again.');
                return;
            }

            var url = config.routes.poll.replace('__ID__', conversationId) + '?after=' + lastMessageId;

            fetch(url, {
                headers: { 'X-CSRF-TOKEN': config.csrfToken }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.success && data.status === 'completed') {
                    stopPolling();
                    hideLoading();

                    var hasContent = false;
                    if (data.messages) {
                        data.messages.forEach(function(msg) {
                            if (msg.id > lastMessageId) {
                                lastMessageId = msg.id;
                            }
                            if (msg.role === 'assistant' && msg.content) {
                                appendMessage('assistant', msg.content, msg.tool_calls);
                                hasContent = true;
                            }
                        });
                    }
                    if (!hasContent) {
                        appendMessage('assistant', 'Sorry, I encountered an error processing your request. Please try again.');
                    }

                    if (data.action_logs && data.action_logs.length > 0) {
                        var lastLog = data.action_logs[data.action_logs.length - 1];
                        showUndoBar(lastLog.id, lastLog.tool_name);
                    }
                }
            })
            .catch(function() {
                // Silently continue polling on network errors
            });
        }, interval);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
    }

    // -----------------------------------------------------------------------
    // Message rendering
    // -----------------------------------------------------------------------

    function appendMessage(role, content, toolCalls) {
        var container = document.getElementById('ai-chat-messages');

        var wrapper = document.createElement('div');
        wrapper.className = 'ai-chat-message ai-chat-' + role;

        var bubble = document.createElement('div');
        bubble.className = 'ai-chat-bubble';
        bubble.innerHTML = formatContent(content);

        if (toolCalls && toolCalls.length > 0) {
            var toolInfo = document.createElement('small');
            toolInfo.className = 'ai-chat-tool-calls text-muted d-block mt-1';
            var toolNames = toolCalls.map(function(t) { return t.name; }).join(', ');
            toolInfo.textContent = 'Used tools: ' + toolNames;
            bubble.appendChild(toolInfo);
        }

        wrapper.appendChild(bubble);
        container.appendChild(wrapper);
        scrollToBottom();
    }

    function formatContent(text) {
        if (!text) return '';

        // Escape HTML
        var escaped = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // Bold: **text**
        escaped = escaped.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Italic: *text*
        escaped = escaped.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Inline code: `code`
        escaped = escaped.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Line breaks
        escaped = escaped.replace(/\n/g, '<br>');

        return escaped;
    }

    function showLoading() {
        isLoading = true;
        var container = document.getElementById('ai-chat-messages');

        var wrapper = document.createElement('div');
        wrapper.className = 'ai-chat-message ai-chat-assistant ai-chat-loading';
        wrapper.id = 'ai-chat-loading-msg';

        var bubble = document.createElement('div');
        bubble.className = 'ai-chat-bubble';
        bubble.innerHTML = '<span class="ai-chat-spinner"></span> Thinking...';

        wrapper.appendChild(bubble);
        container.appendChild(wrapper);
        scrollToBottom();
    }

    function hideLoading() {
        isLoading = false;
        var loading = document.getElementById('ai-chat-loading-msg');
        if (loading) loading.parentNode.removeChild(loading);
    }

    function scrollToBottom() {
        var container = document.getElementById('ai-chat-messages');
        container.scrollTop = container.scrollHeight;
    }

    // -----------------------------------------------------------------------
    // Undo
    // -----------------------------------------------------------------------

    var toolNameLabels = {
        'update_site_config': 'Update site config',
        'update_template_colors': 'Update template colors',
        'create_page': 'Create page',
        'edit_page_content': 'Edit page content',
        'create_article': 'Create article',
        'edit_article_content': 'Edit article content',
        'create_category': 'Create category',
        'generate_image': 'Generate image',
        'edit_template_file': 'Edit template file'
    };

    function showUndoBar(actionLogId, toolName) {
        lastActionLogId = actionLogId;
        var label = toolNameLabels[toolName] || toolName;
        document.getElementById('ai-chat-undo-text').textContent = label;
        document.getElementById('ai-chat-undo').style.display = 'flex';
    }

    function hideUndoBar() {
        document.getElementById('ai-chat-undo').style.display = 'none';
        lastActionLogId = null;
    }

    function executeUndo(actionLogId) {
        var url = config.routes.undo.replace('__ID__', actionLogId);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrfToken
            }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            hideUndoBar();
            if (data.success) {
                appendMessage('assistant', 'Done. Action undone: ' + (data.message || ''));
            } else {
                appendMessage('assistant', 'Undo failed: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function() {
            appendMessage('assistant', 'Connection error during undo.');
        });
    }

    document.getElementById('ai-chat-undo-btn').addEventListener('click', function() {
        if (lastActionLogId) executeUndo(lastActionLogId);
    });

    // -----------------------------------------------------------------------
    // New conversation
    // -----------------------------------------------------------------------

    document.getElementById('ai-chat-new').addEventListener('click', function() {
        stopPolling();
        conversationId = null;
        lastMessageId = 0;
        lastActionLogId = null;
        localStorage.removeItem('ai-chat-conversation-id');
        clearMessages();
        hideUndoBar();
        showWelcome();
    });

    function clearMessages() {
        var container = document.getElementById('ai-chat-messages');
        container.innerHTML = '';
    }

    function showWelcome() {
        var container = document.getElementById('ai-chat-messages');
        container.innerHTML = '<div class="ai-chat-message ai-chat-assistant">'
            + '<div class="ai-chat-bubble">'
            + '<strong>Welcome!</strong> I can help you manage your site. Here\'s what I can do:'
            + '<ul class="mt-2 mb-0 pl-3">'
            + '<li>Create and edit pages &amp; articles</li>'
            + '<li>Update site configuration</li>'
            + '<li>Customize template colors</li>'
            + '<li>Generate images</li>'
            + '<li>Edit template files</li>'
            + '<li>Manage categories</li>'
            + '</ul>'
            + '<small class="text-muted d-block mt-2">Just describe what you\'d like to do!</small>'
            + '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Conversation history
    // -----------------------------------------------------------------------

    function loadConversationHistory(convId) {
        var url = config.routes.history.replace('__ID__', convId);

        fetch(url, {
            headers: { 'X-CSRF-TOKEN': config.csrfToken }
        })
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(function(data) {
            if (data.success && data.messages && data.messages.length > 0) {
                clearMessages();
                data.messages.forEach(function(msg) {
                    if (msg.id > lastMessageId) lastMessageId = msg.id;
                    if ((msg.role === 'user' || msg.role === 'assistant') && msg.content) {
                        appendMessage(msg.role, msg.content, msg.tool_calls);
                    }
                });
                if (data.action_logs && data.action_logs.length > 0) {
                    var lastLog = data.action_logs[data.action_logs.length - 1];
                    showUndoBar(lastLog.id, lastLog.tool_name);
                }
            }
        })
        .catch(function() {
            // If history fails, stay on welcome screen
            conversationId = null;
            localStorage.removeItem('ai-chat-conversation-id');
        });
    }

    // -----------------------------------------------------------------------
    // Conversation list (history button)
    // -----------------------------------------------------------------------

    document.getElementById('ai-chat-history').addEventListener('click', function() {
        fetch(config.routes.conversations, {
            headers: { 'X-CSRF-TOKEN': config.csrfToken }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!data.success || !data.conversations || data.conversations.length === 0) {
                appendMessage('assistant', 'No previous conversations found.');
                return;
            }
            showConversationList(data.conversations);
        })
        .catch(function() {
            appendMessage('assistant', 'Failed to load conversations.');
        });
    });

    function showConversationList(conversations) {
        var container = document.getElementById('ai-chat-messages');

        var wrapper = document.createElement('div');
        wrapper.className = 'ai-chat-message ai-chat-assistant';

        var bubble = document.createElement('div');
        bubble.className = 'ai-chat-bubble ai-chat-conv-list';

        var title = document.createElement('strong');
        title.textContent = 'Previous conversations:';
        bubble.appendChild(title);

        var list = document.createElement('ul');
        list.className = 'list-unstyled mt-2 mb-0';

        conversations.forEach(function(conv) {
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.href = '#';
            link.textContent = conv.title || 'Conversation #' + conv.id;
            link.setAttribute('data-conv-id', conv.id);
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var cid = parseInt(this.getAttribute('data-conv-id'), 10);
                conversationId = cid;
                lastMessageId = 0;
                localStorage.setItem('ai-chat-conversation-id', cid);
                clearMessages();
                hideUndoBar();
                loadConversationHistory(cid);
            });
            item.appendChild(link);
            list.appendChild(item);
        });

        bubble.appendChild(list);
        wrapper.appendChild(bubble);
        container.appendChild(wrapper);
        scrollToBottom();
    }

    // -----------------------------------------------------------------------
    // Event listeners
    // -----------------------------------------------------------------------

    document.getElementById('ai-chat-send').addEventListener('click', sendMessage);

    document.getElementById('ai-chat-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') sendMessage();
    });

})();

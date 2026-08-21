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

    // Loading indicator: whimsical rotating words until a tool runs, then the
    // live tool activity (which the poll already streams back).
    var WHIMSY = ['Thinking', 'Pondering', 'Noodling', 'Tinkering', 'Cooking',
        'Finagling', 'Conjuring', 'Crafting', 'Brewing', 'Wrangling', 'Percolating'];
    var TOOL_LABELS = {
        create_page: 'Creating page', update_page: 'Updating page', delete_page: 'Deleting page',
        edit_page_content: 'Writing page content', set_page_blocks: 'Building layout',
        get_page_blocks: 'Reading layout', list_pages: 'Listing pages', get_page_info: 'Checking page',
        create_article: 'Writing article', edit_article_content: 'Editing article',
        update_article: 'Updating article', list_articles: 'Listing articles', get_article: 'Reading article',
        update_custom_css: 'Applying styles', get_custom_css: 'Reading styles',
        update_template_colors: 'Updating colours', switch_template: 'Switching theme',
        generate_image: 'Generating image', download_image: 'Downloading image', screenshot_url: 'Taking screenshot',
        web_search: 'Searching the web', fetch_url: 'Reading a page', browse_url: 'Browsing the web',
        fetch_page_resources: 'Inspecting the page', database_query: 'Querying the database',
        run_command: 'Running a command', read_file: 'Reading a file', write_file: 'Writing a file',
        edit_file: 'Editing a file', search_files: 'Searching files', list_directory: 'Listing files',
        get_site_info: 'Checking site info', get_site_config: 'Reading settings', update_site_config: 'Updating settings',
        design_system_list: 'Reading design system', design_system_read_file: 'Reading design system',
        design_system_palette: 'Reading palette', design_system_fonts: 'Reading fonts',
        manage_media: 'Managing media', manage_users: 'Managing users', create_category: 'Creating category'
    };
    var whimsyIdx = 0;
    var spinnerTimer = null;
    var activeToolLabel = null;

    function friendlyTool(name) {
        return TOOL_LABELS[name] || (name.charAt(0).toUpperCase() + name.slice(1).replace(/_/g, ' '));
    }

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

        // Abort the POST after 25s — under Cloudflare's 30s cap. The job
        // keeps running server-side via dispatchAfterResponse; we fall
        // through to polling on the conversation's last user message id.
        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var postTimedOut = false;
        var timeoutId = setTimeout(function() {
            postTimedOut = true;
            if (ctrl) ctrl.abort();
        }, 25000);

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
            }),
            signal: ctrl ? ctrl.signal : undefined
        })
        .then(function(res) { clearTimeout(timeoutId); return res.json(); })
        .then(function(data) {
            if (data.success) {
                conversationId = data.conversation_id;
                localStorage.setItem('ai-chat-conversation-id', conversationId);
                lastMessageId = data.message_id || 0;
                // Server now always returns status:'processing' — poll for the
                // assistant's reply (and any tool_call rounds along the way).
                startPolling();
            } else {
                hideLoading();
                appendMessage('assistant', 'Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function(err) {
            clearTimeout(timeoutId);
            // POST aborted (CF cap) or network error. The job is likely still
            // running — start polling against the conversation we have. Best-
            // effort: if we never had a conversationId, surface the error.
            if (postTimedOut && conversationId) {
                startPolling();
                return;
            }
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
        // Long tool chains (research → set_page_blocks → update_custom_css …)
        // can run for several minutes. Keep polling up to 5 min.
        var maxWait = 300000;
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
                if (!data.success || !data.messages) return;

                // Always advance lastMessageId so we don't re-fetch the same
                // intermediate tool-call messages on every poll.
                data.messages.forEach(function(msg) {
                    if (msg.id > lastMessageId) lastMessageId = msg.id;
                });

                if (data.status === 'completed') {
                    stopPolling();
                    hideLoading();

                    // Render only the final assistant content. Intermediate
                    // tool-call messages are noise in the chat panel.
                    data.messages.forEach(function(msg) {
                        if (msg.role === 'assistant' && msg.content && (!msg.tool_calls || msg.tool_calls.length === 0)) {
                            appendMessage('assistant', msg.content, null);
                        }
                    });

                    if (data.action_logs && data.action_logs.length > 0) {
                        var lastLog = data.action_logs[data.action_logs.length - 1];
                        showUndoBar(lastLog.id, lastLog.tool_name);
                    }
                } else {
                    // status === 'processing' → surface the latest tool the
                    // model is running so the spinner shows real progress
                    // instead of a blank wait.
                    data.messages.forEach(function(msg) {
                        if (msg.role === 'assistant' && msg.tool_calls && msg.tool_calls.length) {
                            var tc = msg.tool_calls[msg.tool_calls.length - 1];
                            if (tc && tc.name) {
                                activeToolLabel = friendlyTool(tc.name);
                                setLoadingText(activeToolLabel);
                            }
                        }
                    });
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

    function setLoadingText(text) {
        var bubble = document.querySelector('#ai-chat-loading-msg .ai-chat-bubble');
        if (bubble) {
            bubble.innerHTML = '<span class="ai-chat-spinner"></span> ' +
                text.replace(/</g, '&lt;') + '…';
        }
    }

    function showLoading() {
        isLoading = true;
        activeToolLabel = null;
        whimsyIdx = 0;
        var container = document.getElementById('ai-chat-messages');

        var wrapper = document.createElement('div');
        wrapper.className = 'ai-chat-message ai-chat-assistant ai-chat-loading';
        wrapper.id = 'ai-chat-loading-msg';

        var bubble = document.createElement('div');
        bubble.className = 'ai-chat-bubble';
        wrapper.appendChild(bubble);
        container.appendChild(wrapper);

        setLoadingText(WHIMSY[0]);
        // Once a tool has run, show its live activity; otherwise cycle the
        // whimsical words so the user can see it's still working.
        if (spinnerTimer) clearInterval(spinnerTimer);
        spinnerTimer = setInterval(function() {
            if (activeToolLabel) {
                setLoadingText(activeToolLabel);
            } else {
                whimsyIdx = (whimsyIdx + 1) % WHIMSY.length;
                setLoadingText(WHIMSY[whimsyIdx]);
            }
        }, 2200);

        scrollToBottom();
    }

    function hideLoading() {
        isLoading = false;
        activeToolLabel = null;
        if (spinnerTimer) { clearInterval(spinnerTimer); spinnerTimer = null; }
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
        // Second press closes it — the button is the only way back out when
        // the panel covers the transcript.
        if (document.querySelector('.ai-chat-convs')) {
            closeConversationList();
            return;
        }

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

    /**
     * The picker used to be a bulleted list of titles, and since every title
     * comes out of the same generator ("Conversation #12", "Website help"),
     * twenty rows read as one row repeated. Each row now carries the opening
     * question, when it was last touched, and how much work it holds, so the
     * one being looked for is recognisable at a glance.
     */
    function showConversationList(conversations) {
        // Appended to the transcript it landed below whatever was already
        // there, so opening the list from inside a long conversation scrolled
        // it out of sight and the button looked broken. It is a panel over the
        // transcript instead: it opens where the eye already is, whatever the
        // conversation underneath it holds.
        var sidebar = document.getElementById('ai-chatbot-sidebar');

        closeConversationList();

        var panel = document.createElement('div');
        panel.className = 'ai-chat-convs ai-chat-conv-list';

        var head = document.createElement('div');
        head.className = 'ai-chat-convs-head';

        var heading = document.createElement('span');
        heading.className = 'ai-chat-convs-heading';
        heading.textContent = 'Your conversations';
        head.appendChild(heading);

        var count = document.createElement('span');
        count.className = 'ai-chat-convs-count';
        count.textContent = conversations.length;
        head.appendChild(count);

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'ai-chat-convs-close';
        close.setAttribute('aria-label', 'Close conversation list');
        close.innerHTML = '<i class="fas fa-times"></i>';
        close.addEventListener('click', closeConversationList);
        head.appendChild(close);

        panel.appendChild(head);

        var list = document.createElement('ul');
        list.className = 'ai-chat-convs-list';

        conversations.forEach(function(conv) {
            list.appendChild(buildConversationRow(conv, panel));
        });

        panel.appendChild(list);
        sidebar.appendChild(panel);

        // Between the header and the compose box, both of which stay usable.
        var head_ = sidebar.querySelector('.vela-ai-chat-head');
        var compose = sidebar.querySelector('.vela-ai-compose');
        panel.style.top = ((head_ ? head_.offsetHeight : 56) + 8) + 'px';
        panel.style.bottom = ((compose ? compose.offsetHeight : 60) + 8) + 'px';

        var active = panel.querySelector('.ai-chat-conv.is-active');
        if (active) active.scrollIntoView({ block: 'nearest' });

        document.addEventListener('keydown', conversationListEscape);
        setTimeout(function() { document.addEventListener('mousedown', conversationListOutside); }, 0);
    }

    function closeConversationList() {
        var open = document.querySelector('.ai-chat-convs');
        if (open) open.remove();
        document.removeEventListener('keydown', conversationListEscape);
        document.removeEventListener('mousedown', conversationListOutside);
    }

    function conversationListEscape(e) {
        if (e.key === 'Escape') closeConversationList();
    }

    function conversationListOutside(e) {
        var panel = document.querySelector('.ai-chat-convs');
        if (!panel) return;
        // The history button toggles the panel itself; ignore clicks on it here.
        if (panel.contains(e.target) || e.target.closest('#ai-chat-history')) return;
        closeConversationList();
    }

    function buildConversationRow(conv, panel) {
        var item = document.createElement('li');

        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'ai-chat-conv';
        if (conversationId && parseInt(conv.id, 10) === parseInt(conversationId, 10)) {
            row.classList.add('is-active');
        }

        var title = conv.title || ('Conversation #' + conv.id);

        var avatar = document.createElement('span');
        avatar.className = 'ai-chat-conv-avatar';
        // A stable hue per conversation: colour is the fastest way back to the
        // row someone opened yesterday, and it survives retitling.
        avatar.style.background = 'hsl(' + ((conv.id * 47) % 360) + ' 62% 46%)';
        avatar.textContent = title.trim().charAt(0).toUpperCase() || '#';
        row.appendChild(avatar);

        var body = document.createElement('span');
        body.className = 'ai-chat-conv-body';

        var top = document.createElement('span');
        top.className = 'ai-chat-conv-top';

        var name = document.createElement('span');
        name.className = 'ai-chat-conv-title';
        name.textContent = title;
        top.appendChild(name);

        var when = document.createElement('time');
        when.className = 'ai-chat-conv-time';
        when.textContent = conv.updated_human || '';
        if (conv.updated_at) when.setAttribute('datetime', conv.updated_at);
        if (conv.started_human) when.title = 'Started ' + conv.started_human;
        top.appendChild(when);

        body.appendChild(top);

        if (conv.preview) {
            var preview = document.createElement('span');
            preview.className = 'ai-chat-conv-preview';
            preview.textContent = conv.preview;
            body.appendChild(preview);
        }

        var meta = document.createElement('span');
        meta.className = 'ai-chat-conv-meta';

        if (conv.message_count) {
            meta.appendChild(metaChip('far fa-comment', conv.message_count + (conv.message_count === 1 ? ' message' : ' messages')));
        }
        if (conv.edit_count) {
            var edits = metaChip('fas fa-pen', conv.edit_count + (conv.edit_count === 1 ? ' edit' : ' edits'));
            edits.classList.add('ai-chat-conv-chip-edits');
            meta.appendChild(edits);
        }
        if (row.classList.contains('is-active')) {
            var current = metaChip('fas fa-circle', 'Open now');
            current.classList.add('ai-chat-conv-chip-current');
            meta.appendChild(current);
        }
        if (meta.childNodes.length) body.appendChild(meta);

        row.appendChild(body);

        row.addEventListener('click', function() {
            var cid = parseInt(conv.id, 10);
            conversationId = cid;
            lastMessageId = 0;
            localStorage.setItem('ai-chat-conversation-id', cid);
            closeConversationList();
            clearMessages();
            hideUndoBar();
            loadConversationHistory(cid);
        });

        item.appendChild(row);
        return item;
    }

    function metaChip(iconClass, text) {
        var chip = document.createElement('span');
        chip.className = 'ai-chat-conv-chip';

        var icon = document.createElement('i');
        icon.className = iconClass;
        chip.appendChild(icon);

        var label = document.createElement('span');
        label.textContent = text;
        chip.appendChild(label);

        return chip;
    }

    // -----------------------------------------------------------------------
    // Event listeners
    // -----------------------------------------------------------------------

    document.getElementById('ai-chat-send').addEventListener('click', sendMessage);

    document.getElementById('ai-chat-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') sendMessage();
    });

})();

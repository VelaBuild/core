{{-- WebMCP — expose site tools to AI agents via the browser API --}}
<script>
(function() {
    if (!navigator.modelContext || typeof navigator.modelContext.provideContext !== 'function') return;

    navigator.modelContext.provideContext({
        tools: [
            {
                name: 'search_content',
                description: 'Search published pages and articles on this site',
                inputSchema: {
                    type: 'object',
                    properties: {
                        query: { type: 'string', description: 'Search query (min 2 characters)' }
                    },
                    required: ['query']
                },
                execute: function(params) {
                    return fetch('/api/content/search?q=' + encodeURIComponent(params.query), {
                        headers: { 'Accept': 'application/json' }
                    }).then(function(r) { return r.json(); });
                }
            },
            {
                name: 'list_pages',
                description: 'List all published pages on this site',
                inputSchema: { type: 'object', properties: {} },
                execute: function() {
                    return fetch('/api/content/pages', {
                        headers: { 'Accept': 'application/json' }
                    }).then(function(r) { return r.json(); });
                }
            },
            {
                name: 'get_page',
                description: 'Get a specific page by its URL slug',
                inputSchema: {
                    type: 'object',
                    properties: {
                        slug: { type: 'string', description: 'The page URL slug' }
                    },
                    required: ['slug']
                },
                execute: function(params) {
                    return fetch('/api/content/pages/' + encodeURIComponent(params.slug), {
                        headers: { 'Accept': 'application/json' }
                    }).then(function(r) { return r.json(); });
                }
            },
            {
                name: 'list_posts',
                description: 'List published blog posts, optionally filtered by category',
                inputSchema: {
                    type: 'object',
                    properties: {
                        category: { type: 'string', description: 'Category slug to filter by (optional)' },
                        search: { type: 'string', description: 'Search term to filter by title (optional)' }
                    }
                },
                execute: function(params) {
                    var url = '/api/content/posts';
                    var qs = [];
                    if (params.category) qs.push('category=' + encodeURIComponent(params.category));
                    if (params.search) qs.push('search=' + encodeURIComponent(params.search));
                    if (qs.length) url += '?' + qs.join('&');
                    return fetch(url, {
                        headers: { 'Accept': 'application/json' }
                    }).then(function(r) { return r.json(); });
                }
            },
            {
                name: 'list_categories',
                description: 'List all content categories with post counts',
                inputSchema: { type: 'object', properties: {} },
                execute: function() {
                    return fetch('/api/content/categories', {
                        headers: { 'Accept': 'application/json' }
                    }).then(function(r) { return r.json(); });
                }
            }
        ]
    });
})();
</script>

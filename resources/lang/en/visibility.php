<?php

return [
    'settings_title'       => 'Site Visibility',
    'settings_desc'        => 'Control search engine indexing, AI access, payments, and holding pages.',
    'settings_saved'       => 'Site visibility settings saved.',

    'mode_label'           => 'Site Visibility',
    'mode_public'          => 'Public Online',
    'mode_public_desc'     => 'Your site is fully accessible and search engines are invited to index it.',
    'mode_restricted'      => 'Restricted',
    'mode_restricted_desc' => 'Apply restrictions below to limit how your site is discovered or accessed.',

    'suboptions_help'      => 'Select one or more restrictions. If none are selected, search engine indexing will be disabled by default.',

    'opt_noindex'          => 'Request search engines not to index',
    'opt_noindex_desc'     => 'Adds a noindex meta tag and updates robots.txt to discourage search engines from indexing your site. Note: this is a request — not all crawlers honour it.',
    'opt_block_ai'         => 'Restrict AI / LLM access',
    'opt_block_ai_desc'    => 'Updates robots.txt to block known AI training crawlers (GPTBot, ChatGPT, ClaudeBot, Google-Extended, CCBot, and others).',
    'opt_holding'          => 'Temporary holding page',
    'opt_holding_desc'     => 'Redirects all public visitors to a specific page (e.g., "Coming Soon"). Admin users can still browse the full site.',

    'holding_page_select'  => 'Select holding page',
    'holding_page_none'    => 'Select a page',
    'holding_page_help'    => 'Choose a page to display to visitors. All other public pages will redirect here.',

    // x402 AI Payment
    'x402_title'           => 'AI Payment (x402 Protocol)',
    'x402_intro'           => 'Require AI agents to pay for content access using the x402 protocol. This works independently of the public/restricted setting above — regular browsers are never affected. AI agents that pay receive full access; those that don\'t receive HTTP 402.',
    'x402_enable'          => 'Require payments from AI to access (x402)',
    'x402_mode_label'      => 'Payment Scope',
    'x402_mode_sitewide'   => 'Sitewide',
    'x402_mode_sitewide_desc' => 'All pages require AI payment at the price set below.',
    'x402_mode_per_page'   => 'Per Page',
    'x402_mode_per_page_desc' => 'Enable and set pricing on individual pages. The price below is the default for pages that don\'t specify their own.',
    'x402_wallet'          => 'Wallet Address',
    'x402_wallet_help'     => 'Your wallet address that receives USDC payments. Required when x402 is enabled.',
    'x402_price'           => 'Default Price per Request (USD)',
    'x402_price_help'      => 'Amount in USD charged per AI request. Paid in USDC stablecoin.',
    'x402_network'         => 'Payment Network',
    'x402_network_help'    => 'Blockchain network for receiving payments. Base is recommended for lowest fees.',
    'x402_description'     => 'Content Description',
    'x402_description_help' => 'Shown to AI agents in the payment request. Describes what they\'re paying for.',
    'x402_page_title'      => 'AI Payment (x402)',
    'x402_page_enable'     => 'Require AI payment for this page',
    'x402_page_price'      => 'Price (USD)',
    'x402_page_price_help' => 'Leave empty to use the default price from settings.',
];

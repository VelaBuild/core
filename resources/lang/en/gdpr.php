<?php

return [
    // Cookie consent banner
    'banner_text'         => 'We use cookies to ensure the best experience on our site. Essential cookies are always active. You can choose to enable analytics cookies to help us improve.',
    'accept_all'          => 'Accept All',
    'necessary_only'      => 'Necessary Only',
    'manage'              => 'Manage',
    'save'                => 'Save Preferences',
    'privacy_link'        => 'Privacy Policy',

    'cat_necessary'       => 'Essential',
    'cat_necessary_desc'  => 'Required for the site to function. These cannot be turned off.',
    'cat_functional'      => 'Functional',
    'cat_functional_desc' => 'Remembers your preferences such as language and UI state.',
    'cat_analytics'       => 'Analytics',
    'cat_analytics_desc'  => 'Helps us understand how visitors use the site so we can improve it.',

    // Admin settings
    'settings_title'      => 'GDPR & Cookie Consent',
    'settings_desc'       => 'Cookie consent banner, privacy policy, and analytics controls.',
    'settings_saved'      => 'GDPR settings saved.',

    'enable_label'        => 'Enable cookie consent banner',
    'enable_help'         => 'When enabled, a cookie consent banner is shown to all visitors and analytics scripts are blocked until consent is granted.',
    'env_active'          => 'Environment variable :var is set to :value. Saving here will override it.',

    'privacy_url_label'   => 'Privacy Policy URL',
    'privacy_url_help'    => 'The URL path to your privacy policy page. The consent banner links to this page.',

    'privacy_page_section'      => 'Privacy Policy Page',
    'privacy_page_found'        => 'A published page exists at /:slug.',
    'privacy_page_missing'      => 'No published page found at /:slug. Visitors who click the privacy link will see a 404.',
    'privacy_page_exists'       => 'A page with this slug already exists.',
    'privacy_page_restored'     => 'A previously deleted privacy page was found and restored.',
    'privacy_page_title'        => 'Privacy Policy',
    'privacy_page_created'      => 'Privacy policy page created. Review and edit it to match your site.',
    'view_page'                 => 'View page',
    'install_privacy_page'      => 'Create Privacy Policy Page',
    'install_privacy_page_help' => 'Creates a starter privacy policy page that you can review and edit.',

    'what_this_does'      => 'What does this do?',
    'info_banner'         => 'Shows a cookie consent banner at the bottom of every public page.',
    'info_analytics'      => 'Blocks Google Analytics from loading until the visitor grants analytics consent.',
    'info_consent_cookie' => 'Stores the visitor\'s consent choice in a first-party cookie (valid for 1 year).',
    'info_categories'     => 'Lets visitors choose between Essential, Functional, and Analytics cookie categories.',
];

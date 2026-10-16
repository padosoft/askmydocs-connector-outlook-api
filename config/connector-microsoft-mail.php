<?php

declare(strict_types=1);

return [
    'client_id' => env('MICROSOFT_MAIL_CLIENT_ID'),
    'client_secret' => env('MICROSOFT_MAIL_CLIENT_SECRET'),
    'redirect_uri' => env('MICROSOFT_MAIL_REDIRECT_URI'),
    'authority' => env('MICROSOFT_MAIL_AUTHORITY', 'common'),
    'http' => ['timeout' => 15, 'connect_timeout' => 5, 'retries' => 2, 'max_retry_wait_seconds' => 5, 'max_pages' => 1000, 'max_attachment_items' => 1000, 'max_attachment_response_bytes' => 100 * 1024 * 1024, 'max_folders' => 10000, 'max_folder_depth' => 100, 'max_response_bytes' => 50 * 1024 * 1024],
    'defaults' => [
        'mailbox' => ['target_id' => null],
        'folders' => ['include' => [], 'exclude' => [], 'include_hidden' => false, 'include_deleted' => false, 'include_junk' => false],
        'sync' => ['date_window_days' => 0, 'max_pages' => 20, 'max_messages' => 500, 'max_bytes' => 100 * 1024 * 1024, 'time_budget_seconds' => 60, 'page_size' => 100],
        'filters' => ['only_unseen' => false, 'only_flagged' => false, 'skip_auto_generated' => true, 'senders' => ['include' => [], 'exclude' => []], 'recipients' => ['include' => [], 'exclude' => []], 'subject' => ['include_keywords' => [], 'exclude_keywords' => []]],
        'body' => ['format' => 'prefer_text', 'strip_quoted_history' => false],
        'attachments' => ['enabled' => true, 'allowed_extensions' => ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv', 'md', 'rtf', 'odt'], 'max_size_mb' => 25, 'max_per_email' => 20, 'skip_inline' => true, 'max_total_bytes' => 50 * 1024 * 1024, 'max_depth' => 3],
        'reconciliation' => ['enabled' => false],
    ],
];

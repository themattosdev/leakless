<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan;

class IncompatibleFunctionsFixture
{
    public function handle(): void
    {
        get_browser();
        setcookie('session_id', '12345');
        header('X-Custom-Header: leaked');
        http_response_code(200);
        session_id('custom_session');
        flush();
        glob('*.{php,md}', GLOB_BRACE);
        imap_open('{localhost:993/imap/ssl}INBOX', 'user', 'pass');
    }
}

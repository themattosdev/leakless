<?php

declare(strict_types=1);

namespace Tests\Fixtures\PHPStan;

class SuperglobalsFixture
{
    public function handle(): void
    {
        $id = $_GET['id'] ?? null;
        $name = $_POST['name'] ?? null;
        $session = $_SESSION['user'] ?? null;
        $req = $_REQUEST['data'] ?? null;
        $files = $_FILES['avatar'] ?? null;

        session_start();

        if ($id === null) {
            exit(1);
        }
    }
}


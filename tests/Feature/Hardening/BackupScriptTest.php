<?php

namespace Tests\Feature\Hardening;

use Tests\TestCase;

class BackupScriptTest extends TestCase
{
    public function test_backup_script_exists(): void
    {
        $this->assertFileExists(base_path('scripts/backup_postgres.sh'));
    }

    public function test_restore_script_exists(): void
    {
        $this->assertFileExists(base_path('scripts/restore_postgres.sh'));
    }

    public function test_backup_script_uses_pg_dump(): void
    {
        $content = file_get_contents(base_path('scripts/backup_postgres.sh'));

        $this->assertStringContainsString('pg_dump', $content);
    }

    public function test_restore_script_uses_pg_restore(): void
    {
        $content = file_get_contents(base_path('scripts/restore_postgres.sh'));

        $this->assertStringContainsString('pg_restore', $content);
    }
}

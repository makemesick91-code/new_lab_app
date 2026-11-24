<?php

namespace App\Support\Database;

use Attribute;

/**
 * Declares the runtime privilege class of a table a migration creates
 * (rule 245 #6; owner decision D-Q3-b, DMS-SPN4-READY-PILOT-1 R6-B2).
 *
 * Every migration after the historical cutoff in config/runtime_privilege_classes.php
 * that creates a table carries one attribute per table:
 *
 *     return new #[RuntimePrivilegeClass('trx_example', RuntimePrivilegeClass::FULL_DML)] class extends Migration { … };
 *
 * The attribute only records the class. A migration never executes GRANT or
 * REVOKE: grants are applied by the authorised R6-B3 provisioning, with a dry run,
 * verification and rollback.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class RuntimePrivilegeClass
{
    /** SELECT, INSERT, UPDATE, DELETE. */
    public const FULL_DML = 'FULL_DML';

    /** SELECT, INSERT. Rows are never changed or deleted. */
    public const APPEND_ONLY = 'APPEND_ONLY';

    /** SELECT, INSERT, and UPDATE of the named columns only (trigger-guarded). */
    public const APPEND_ONLY_WITH_COLUMNS = 'APPEND_ONLY_WITH_COLUMNS';

    /** SELECT only. */
    public const READ_ONLY_RUNTIME = 'READ_ONLY_RUNTIME';

    public const CLASSES = [
        self::FULL_DML,
        self::APPEND_ONLY,
        self::APPEND_ONLY_WITH_COLUMNS,
        self::READ_ONLY_RUNTIME,
    ];

    /** @param  list<string>  $columns  the updatable columns, for APPEND_ONLY_WITH_COLUMNS only */
    public function __construct(
        public readonly string $table,
        public readonly string $class,
        public readonly array $columns = [],
    ) {}
}

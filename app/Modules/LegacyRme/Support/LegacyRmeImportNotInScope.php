<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * LEGACY-RME-OPS-CLI-1 — the import does not exist, or it exists outside the
 * caller's server-resolved branch scope.
 *
 * ONE OUTCOME FOR BOTH CASES, DELIBERATELY. Answering "no such import" for one
 * and "not yours" for the other turns the lookup into an existence oracle: an
 * operator pinned to LDK2 could enumerate ids and learn how many documents
 * TKM1 has staged. The archive is clinical evidence; its cardinality is not
 * public either.
 *
 * IT EXTENDS NotFoundHttpException ON PURPOSE. The HTTP surface has always
 * answered 404 here (`abort_if($import === null, 404)`), and routing the
 * controller through the shared lifecycle service must not change that byte.
 * Laravel renders this as 404 with no handler change; the CLI catches the type
 * explicitly and reports IMPORT_NOT_IN_SCOPE with a non-zero exit.
 */
class LegacyRmeImportNotInScope extends NotFoundHttpException
{
    public readonly string $refusalCode;

    public function __construct(string $message = 'Impor arsip RME lama tidak ditemukan.')
    {
        parent::__construct($message);

        $this->refusalCode = LegacyRmeLifecycleRefusal::IMPORT_NOT_IN_SCOPE;
    }
}

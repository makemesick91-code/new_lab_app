<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * DOCTOR-PWA-GLOBAL-ROLLOUT-READINESS-1 — a forbidden console command was
 * refused before it ran. Salvaged from the closed PHASE4A-DOCTOR-ANDROID-PILOT-
 * ACTIVATION-1 pull request.
 *
 * Deliberately NOT reported. Half the reason the REPL is forbidden is that it
 * writes ERROR records into the application log, which pins the monitoring log
 * signal to WATCH for 24 hours and blinds it to genuine errors. A guard that
 * logged an ERROR on every refusal would cause the exact harm it exists to
 * prevent, so this is registered in `dontReport` and says its piece on the
 * console instead.
 */
class ForbiddenProductionCommandException extends RuntimeException {}

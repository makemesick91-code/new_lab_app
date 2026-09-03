<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 —
 * trx_doctor_device_login_tickets.
 *
 * The bridge between the device channel and a web session, and the reason the
 * app-only gate needs no header trust.
 *
 * The Clinic App collects credentials natively and proves its key on the
 * stateless device channel. A session cookie, though, has to be established by
 * the WebView. So once the server has ALREADY decided the login is permitted it
 * mints a ticket, and the WebView redeems it at a web route.
 *
 * WHAT MAKES THIS SAFE
 *  - `token_hash` only. The plaintext is returned to the proven device exactly
 *    once, so a database reader cannot redeem anybody's login.
 *  - `expires_at` is seconds, not hours, and `consumed_at` makes it single-use
 *    under a row lock.
 *  - It is bound to the user, the doctor, the device AND the authorization. A
 *    ticket cannot be replayed onto a different account or a different tablet.
 *  - It is minted ONLY when enforcement is on, the authorization is ACTIVE and
 *    the device is administratively usable — and every one of those is
 *    re-asserted again at redemption. With enforcement off no ticket can exist,
 *    and redemption fails closed even if one somehow did.
 *
 * A ticket is not a credential the client asserts; it is a receipt for a
 * decision the server already made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trx_doctor_device_login_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Hash only — the plaintext ticket is never persisted.
            $table->string('token_hash', 128)->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('mst_doctors')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_device_id')->constrained('mst_doctor_devices')
                ->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('doctor_device_authorization_id')
                ->constrained('mst_doctor_device_authorizations')
                ->cascadeOnUpdate()->restrictOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'expires_at'], 'trx_dd_login_tickets_user_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_doctor_device_login_tickets');
    }
};

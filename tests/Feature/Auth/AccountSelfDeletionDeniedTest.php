<?php

/**
 * SECURITY-F2-DENY-SELF-DELETION-1 — a user cannot delete their own account.
 *
 * `DELETE /profile` hard-deleted the user row (`forceDelete()`), which nulls every
 * attribution column that names the user (audit actor, record author, consent
 * signer, publisher, approver) and cascade-deletes trail tables. Decision D2:
 * DENY. An administrator deactivates an account instead; deactivation refuses
 * login and ends live sessions (covered by ActiveAccountSessionTest).
 */

use App\Models\User;
use Illuminate\Support\Facades\Route;

test('a user cannot delete their own account', function () {
    $user = User::factory()->create();
    $before = $user->fresh()->getAttributes();

    $this->actingAs($user)
        ->delete('/profile', ['password' => 'password'])
        ->assertMethodNotAllowed();

    $this->assertAuthenticatedAs($user);
    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()->getAttributes())->toBe($before)
        ->and(User::withTrashed()->whereKey($user->id)->value('deleted_at'))->toBeNull();
});

test('the profile page offers no account deletion', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertDontSee('confirm-user-deletion')
        ->assertDontSee('name="_method" value="delete"', false);

    expect(Route::has('profile.destroy'))->toBeFalse();
});

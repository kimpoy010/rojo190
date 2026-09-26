<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'player']);
    }

    private function player(): User
    {
        $user = User::factory()->create(['password' => Hash::make('original-password')]);
        $user->assignRole('player');
        Wallet::create(['user_id' => $user->id]);

        return $user;
    }

    public function test_a_guest_can_view_the_forgot_password_form(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    public function test_an_authenticated_user_is_redirected_away_from_the_forgot_password_form(): void
    {
        $user = $this->player();

        $this->actingAs($user)->get(route('password.request'))->assertRedirect();
    }

    public function test_requesting_a_reset_link_for_a_registered_email_sends_the_notification(): void
    {
        Notification::fake();
        $user = $this->player();

        $response = $this->post(route('password.email'), ['email' => $user->email]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    /**
     * Deliberately the same outcome as a registered email — telling the
     * two apart would turn this form into an oracle for probing which
     * emails have accounts.
     */
    public function test_requesting_a_reset_link_for_an_unregistered_email_gives_the_same_response(): void
    {
        Notification::fake();

        $response = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password(): void
    {
        Notification::fake();
        $user = $this->player();

        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success');
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = $this->player();

        $response = $this->post(route('password.update'), [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        Notification::fake();
        $user = $this->player();

        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'does-not-match',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_resetting_the_password_writes_an_audit_log_entry(): void
    {
        Notification::fake();
        $user = $this->player();

        $this->post(route('password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $log = AuditLog::where('action', 'account.password_reset')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertSame($user->id, $log->target_id);
    }
}

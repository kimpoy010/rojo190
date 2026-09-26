<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperadminPinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'superadmin']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('superadmin');

        return $admin;
    }

    public function test_superadmin_can_set_a_pin_when_none_is_set(): void
    {
        $admin = $this->admin();
        $this->assertFalse($admin->hasPin());

        $response = $this->actingAs($admin)->put(route('superadmin.pin.update'), [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertTrue($admin->fresh()->hasPin());
    }

    public function test_changing_an_existing_pin_requires_the_current_one(): void
    {
        $admin = $this->admin();
        $admin->update(['pin' => '1234']);

        $response = $this->actingAs($admin)->put(route('superadmin.pin.update'), [
            'current_pin' => '0000',
            'pin' => '5678',
            'pin_confirmation' => '5678',
        ]);

        $response->assertSessionHas('error');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('1234', $admin->fresh()->pin));
    }

    public function test_changing_an_existing_pin_with_the_correct_current_pin_succeeds(): void
    {
        $admin = $this->admin();
        $admin->update(['pin' => '1234']);

        $response = $this->actingAs($admin)->put(route('superadmin.pin.update'), [
            'current_pin' => '1234',
            'pin' => '5678',
            'pin_confirmation' => '5678',
        ]);

        $response->assertSessionHas('success');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('5678', $admin->fresh()->pin));
    }

    public function test_pin_must_be_confirmed(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->put(route('superadmin.pin.update'), [
            'pin' => '1234',
            'pin_confirmation' => '4321',
        ]);

        $response->assertSessionHasErrors('pin');
        $this->assertFalse($admin->fresh()->hasPin());
    }
}

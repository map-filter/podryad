<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndAccessTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    public function test_login_with_seed_password_and_me(): void
    {
        $res = $this->postJson('/api/auth/login', ['email' => 'head@ertis.test', 'password' => 'portal2026'])->assertOk();
        $token = $res->json('token');
        $this->assertNotEmpty($token);
        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'head')
            ->assertJsonPath('organization.bin', '150340012340');
    }

    public function test_wrong_password_is_rejected_in_russian(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'head@ertis.test', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Неверная почта или пароль');
    }

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/workers')->assertStatus(401)->assertJsonPath('message', 'Войдите в кабинет');
    }

    public function test_customer_cannot_open_contractor_api_and_contractor_cannot_open_customer_api(): void
    {
        $this->actingAsEmail('ot.pnhz@zakazchik.test');
        $this->getJson('/api/workers')->assertStatus(403);
        $this->getJson('/api/customer/requests')->assertOk();
        $this->asHead();
        $this->getJson('/api/customer/requests')->assertStatus(403);
    }

    public function test_foreman_can_read_but_not_edit_workers(): void
    {
        $this->actingAsEmail('master@ertis.test');
        $this->getJson('/api/workers')->assertOk();
        $this->postJson('/api/workers', [
            'last_name' => 'Тестов', 'first_name' => 'Тест', 'iin' => '880314300158', 'position' => 'Слесарь',
        ])->assertStatus(403);
    }

    public function test_seed_has_expected_volume(): void
    {
        $this->asHead();
        $workers = $this->getJson('/api/workers')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(25, count($workers));
        $this->assertLessThanOrEqual(30, count($workers));
        $statuses = array_count_values(array_map(fn ($w) => $w['readiness']['status'], $workers));
        $this->assertArrayHasKey('ready', $statuses);
        $this->assertArrayHasKey('expiring', $statuses);
        $this->assertArrayHasKey('not_ready', $statuses);
        foreach ($workers as $w) {
            $this->assertTrue(\App\Support\Iin::isValid($w['iin']), $w['iin']);
        }
        $requests = $this->getJson('/api/requests')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['approved', 'submitted', 'draft'], array_column($requests, 'status'));
        $this->assertGreaterThanOrEqual(4, count($this->getJson('/api/profiles')->json('data')));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-only-jwt-secret-for-feature-tests']);
    }

    public function test_protected_profile_requires_a_valid_token(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);
    }

    public function test_company_user_can_login_get_profile_and_revoke_token(): void
    {
        $user = $this->createCompanyUser();

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonStructure(['access_token']);

        $token = $login->json('access_token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.company_id', $user->company_id)
            ->assertJsonMissingPath('user.password');

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = $this->createCompanyUser();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
    }

    public function test_company_user_can_refresh_their_token(): void
    {
        $user = $this->createCompanyUser();
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('access_token');

        $refresh = $this->withToken($token)
            ->postJson('/api/auth/refresh')
            ->assertOk()
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonStructure(['access_token']);
        $newToken = $refresh->json('access_token');

        $this->withToken($newToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_unassigned_user_cannot_obtain_a_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonMissingPath('access_token');
    }

    private function createCompanyUser(): User
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);
        $user = User::factory()->create();
        $user->company()->associate($company);
        $user->save();

        return $user;
    }
}

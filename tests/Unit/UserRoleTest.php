<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    private function user(string $role, string $status = User::STATUS_PENDING): User
    {
        $user = new User();
        $user->setRawAttributes([
            'role' => $role,
            'account_status' => $status,
        ]);

        return $user;
    }

    public function test_farmer_role_helpers(): void
    {
        $user = $this->user(User::ROLE_FARMER);

        $this->assertTrue($user->isFarmer());
        $this->assertFalse($user->isMao());
        $this->assertFalse($user->isBarangay());
    }

    public function test_mao_role_helpers(): void
    {
        $user = $this->user(User::ROLE_MAO);

        $this->assertTrue($user->isMao());
        $this->assertFalse($user->isFarmer());
    }

    public function test_barangay_role_helpers(): void
    {
        $user = $this->user(User::ROLE_BARANGAY);

        $this->assertTrue($user->isBarangay());
        $this->assertFalse($user->isMao());
    }

    public function test_has_role_matches_exact_role(): void
    {
        $user = $this->user(User::ROLE_MAO);

        $this->assertTrue($user->hasRole('mao'));
        $this->assertFalse($user->hasRole('farmer'));
    }

    public function test_is_verified_only_for_verified_status(): void
    {
        $this->assertTrue($this->user(User::ROLE_FARMER, User::STATUS_VERIFIED)->isVerified());
        $this->assertFalse($this->user(User::ROLE_FARMER, User::STATUS_PENDING)->isVerified());
        $this->assertFalse($this->user(User::ROLE_FARMER, User::STATUS_REJECTED)->isVerified());
    }

    public function test_password_is_hidden_from_json(): void
    {
        $user = new User();
        $user->setRawAttributes(['password' => 'secret', 'first_name' => 'JUAN']);

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayHasKey('first_name', $user->toArray());
    }
}

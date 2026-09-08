<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Marque\Trove\Concerns\HasRoles;
use Marque\Trove\Contracts\UserInterface;

/**
 * The host app's user model, as far as taxonomy is concerned.
 *
 * Taxonomy never ships a user model — trove's config points at whatever the
 * host uses. This stands in for it under test, and exists only because
 * torrents need an owner.
 */
class TestUser extends Authenticatable implements UserInterface
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    protected $attributes = [
        'role' => 'user',
        'status' => 'active',
    ];

    public function generateAnnounceKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}

<?php

namespace VelaBuild\Core\Support;

use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;

/**
 * CMS seats on hosted plans. A seat is a user holding any role other than
 * Guest — the people who can work in the admin. Self-registered visitors get
 * Guest only, so a public sign-up form never uses up a plan's seats.
 */
class Seats
{
    public static function limit(): ?int
    {
        return vela_seat_limit();
    }

    public static function used(?int $exceptUserId = null): int
    {
        $guestId = Role::guest()->id;

        return VelaUser::query()
            ->when($exceptUserId, fn ($q) => $q->whereKeyNot($exceptUserId))
            ->whereHas('roles', fn ($q) => $q->where('vela_roles.id', '!=', $guestId))
            ->count();
    }

    /**
     * Would giving $roleIds to $user (null = a new user) go over the plan's seats?
     *
     * @param  array<int|string>  $roleIds
     */
    public static function wouldExceed(array $roleIds, ?VelaUser $user = null): bool
    {
        $limit = static::limit();
        if ($limit === null) {
            return false;
        }
        $guestId = Role::guest()->id;
        $takesSeat = collect($roleIds)->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === $guestId)->isNotEmpty();
        if (!$takesSeat) {
            return false;
        }

        return static::used($user?->id) + 1 > $limit;
    }

    public static function message(): string
    {
        $limit = static::limit();

        return trans_choice(
            'Your plan includes :count CMS user. Remove a user or upgrade the plan to add another.|Your plan includes :count CMS users. Remove a user or upgrade the plan to add another.',
            $limit ?? 0,
            ['count' => $limit]
        );
    }
}

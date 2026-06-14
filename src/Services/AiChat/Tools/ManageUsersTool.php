<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaUser;

class ManageUsersTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $action = $parameters['action'] ?? 'list';

        return match ($action) {
            'list' => $this->listUsers($parameters),
            'info' => $this->getUserInfo($parameters),
            default => ['error' => "Unknown action: {$action}. Available: list, info"],
        };
    }

    private function listUsers(array $params): array
    {
        $users = VelaUser::with('roles')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->roles->pluck('title')->toArray(),
                'created' => $u->created_at->toDateTimeString(),
                'last_login' => $u->last_login_at,
            ]);

        return ['users' => $users->toArray(), 'count' => $users->count()];
    }

    private function getUserInfo(array $params): array
    {
        $id = $params['id'] ?? null;
        if (!$id) return ['error' => 'id is required'];

        $user = VelaUser::with('roles')->find($id);
        if (!$user) return ['error' => 'User not found'];

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('title')->toArray(),
            'created' => $user->created_at->toDateTimeString(),
            'last_login' => $user->last_login_at,
            'two_factor' => $user->two_factor_enabled ?? false,
        ];
    }
}

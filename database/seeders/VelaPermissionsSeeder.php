<?php

namespace VelaBuild\Core\Database\Seeders;

use VelaBuild\Core\Models\Permission;
use Illuminate\Database\Seeder;

class VelaPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'user_management_access',
            'user_create',
            'user_edit',
            'user_show',
            'user_delete',
            'user_access',
            'permission_access',
            'permission_create',
            'permission_edit',
            'permission_show',
            'permission_delete',
            'role_access',
            'role_create',
            'role_edit',
            'role_show',
            'role_delete',
            'category_access',
            'category_create',
            'category_edit',
            'category_show',
            'category_delete',
            'article_access',
            'article_create',
            'article_edit',
            'article_show',
            'article_delete',
            'translation_access',
            'translation_create',
            'translation_edit',
            'translation_show',
            'translation_delete',
            'idea_access',
            'idea_create',
            'idea_edit',
            'idea_show',
            'idea_delete',
            'config_access',
            'config_create',
            'config_edit',
            'config_show',
            'config_delete',
            'comment_access',
            'comment_create',
            'comment_edit',
            'comment_show',
            'comment_delete',
            'profile_password_edit',
            'page_access',
            'page_create',
            'page_edit',
            'page_show',
            'page_delete',
            'form_submission_access',
            'form_submission_show',
            'form_submission_delete',
            'ai_chat_access',
            'ai_chat_template_edit',
            'ai_chat_content_manage',
            'ai_chat_config_manage',
            'tools_access',
            'admin_tools_access',
            'theme_check_access',
            'design_builder_access',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['title' => $name]);
        }
    }
}

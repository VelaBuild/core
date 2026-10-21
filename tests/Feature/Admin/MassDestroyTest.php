<?php

namespace VelaBuild\Core\Tests\Feature\Admin;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use PHPUnit\Framework\Attributes\DataProvider;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Comment;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\FormSubmission;
use VelaBuild\Core\Models\Idea;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Permission;
use VelaBuild\Core\Models\Role;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Tests\TestCase;

/**
 * Every list page's "delete selected": all the selected rows go, or, when one
 * of them cannot, none do — never some now and the rest on the next try.
 */
class MassDestroyTest extends TestCase
{
    public static function lists(): array
    {
        return [
            'users'            => [VelaUser::class, '/admin/users/destroy'],
            'categories'       => [Category::class, '/admin/categories/destroy'],
            'articles'         => [Content::class, '/admin/articles/destroy'],
            'pages'            => [Page::class, '/admin/pages/destroy'],
            'ideas'            => [Idea::class, '/admin/ideas/destroy'],
            'comments'         => [Comment::class, '/admin/comments/destroy'],
            'roles'            => [Role::class, '/admin/roles/destroy'],
            'permissions'      => [Permission::class, '/admin/permissions/destroy'],
            'form submissions' => [FormSubmission::class, '/admin/form-submissions/destroy'],
        ];
    }

    #[DataProvider('lists')]
    public function test_deletes_every_selected_row(string $model, string $url): void
    {
        $this->loginAsAdmin();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        [$first, $second, $kept] = $this->make($model, 3)->all();

        // As the list page sends them: strings, newest first.
        $this->post($url, ['ids' => [(string) $second->id, (string) $first->id], '_method' => 'DELETE'])
            ->assertNoContent();

        $this->assertNull($model::find($first->id));
        $this->assertNull($model::find($second->id));
        $this->assertNotNull($model::find($kept->id));
    }

    #[DataProvider('lists')]
    public function test_deletes_none_when_one_fails(string $model, string $url): void
    {
        $this->loginAsAdmin();
        $this->withoutMiddleware(VerifyCsrfToken::class);

        [$first, $second] = $this->make($model, 2)->all();

        $model::deleting(function ($row) use ($second) {
            if ($row->id === $second->id) {
                throw new \RuntimeException('database went away');
            }
        });

        $this->post($url, ['ids' => [$first->id, $second->id], '_method' => 'DELETE'])
            ->assertStatus(500);

        $this->assertNotNull($model::find($first->id));
        $this->assertNotNull($model::find($second->id));
    }

    private function make(string $model, int $count)
    {
        // A submission belongs to a page, which its factory leaves out.
        $attributes = $model === FormSubmission::class ? ['page_id' => Page::factory()] : [];

        return $model::factory()->count($count)->create($attributes);
    }
}

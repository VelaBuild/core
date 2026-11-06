<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\File;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\StaticSiteGenerator;
use VelaBuild\Core\Tests\TestCase;

class StaticWriteSwitchTest extends TestCase
{
    public function test_read_only_static_files_are_never_written_or_deleted(): void
    {
        $base = sys_get_temp_dir() . '/vela-static-' . uniqid();
        File::ensureDirectoryExists($base . '/pages/kept');
        File::put($base . '/pages/kept/index.html', 'original');
        config(['vela.static.path' => $base, 'vela.static.write' => false]);

        $generator = new StaticSiteGenerator();
        $this->assertFalse($generator->writable());

        $page = Page::create(['title' => 'Kept', 'slug' => 'kept', 'locale' => 'en', 'status' => 'published']);
        $generator->writeConfigJson($page);
        $generator->removeHtml('page', 'kept');
        $generator->removeAll('page', 'kept');

        $this->assertSame('original', File::get($base . '/pages/kept/index.html'));
        $this->assertFileDoesNotExist($base . '/pages/kept/config.json');
        File::deleteDirectory($base);
    }
}

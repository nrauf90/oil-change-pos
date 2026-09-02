<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FrontendHeroiconTest extends TestCase
{
    public function test_app_owned_blade_views_contain_no_emoji_interface_glyphs(): void
    {
        $offendingViews = [];

        foreach (File::allFiles(resource_path('views')) as $view) {
            if (! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            $source = html_entity_decode($view->getContents(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (preg_match('/[\x{2600}-\x{27BF}\x{1F300}-\x{1FAFF}]/u', $source) === 1) {
                $offendingViews[] = $view->getRelativePathname();
            }
        }

        $this->assertSame(
            [],
            $offendingViews,
            'Replace emoji interface glyphs with Heroicons in: '.implode(', ', $offendingViews),
        );
    }
}

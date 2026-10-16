<?php

namespace Dgtlss\EasyErd\Tests;

use Illuminate\Support\Facades\File;

class ImageTest extends TestCase
{
    public function test_graphviz_receives_safe_paths_and_model_relationships_and_temp_files_are_removed(): void
    {
        $this->model('User', <<<'PHP'
namespace App\Models;
class User {
    public function posts(): HasMany { return $this->hasMany(Post::class); }
}
PHP);
        $renderer = $this->directory.'/fake graphviz';
        File::put($renderer, <<<'PHP'
#!/usr/bin/env php
<?php
$source = $argv[count($argv) - 3];
file_put_contents(end($argv), json_encode(['dot' => file_get_contents($source), 'temp' => $source]));
PHP);
        chmod($renderer, 0755);
        $output = $this->directory.'/diagram with spaces; literal.svg';

        $this->generator(['graphviz_path' => $renderer])->generateImage($output, 'svg');

        $data = json_decode(File::get($output), true);
        $this->assertStringContainsString('"users" -> "posts"', $data['dot']);
        $this->assertStringContainsString('label="posts"', $data['dot']);
        $this->assertFileDoesNotExist($data['temp']);
    }

    public function test_graphviz_failure_is_reported_and_removes_the_temp_file(): void
    {
        $capture = $this->directory.'/captured.txt';
        $renderer = $this->directory.'/failing-graphviz';
        File::put($renderer, "#!/usr/bin/env php\n<?php\nfile_put_contents(".var_export($capture, true).", \$argv[count(\$argv) - 3]);\nfwrite(STDERR, 'render failed');\nexit(1);\n");
        chmod($renderer, 0755);

        try {
            $this->generator(['graphviz_path' => $renderer])->generateImage($this->directory.'/diagram.svg', 'svg');
            $this->fail('A failed renderer must throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('render failed', $exception->getMessage());
            $this->assertFileDoesNotExist(File::get($capture));
        }
    }
}

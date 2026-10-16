<?php

namespace Dgtlss\EasyErd\Tests;

use Illuminate\Support\Facades\File;

class CommandTest extends TestCase
{
    public function test_drift_check_fails_for_missing_or_changed_output_and_never_writes(): void
    {
        $path = $this->directory.'/docs/erd.md';
        $this->artisan('easyerd:generate', ['--check' => true])->assertFailed();
        $this->assertDirectoryDoesNotExist(dirname($path));

        $this->artisan('easyerd:generate', ['--type' => 'mermaid'])->assertSuccessful();
        $original = File::get($path);
        $this->artisan('easyerd:generate', ['--check' => true])->assertSuccessful();

        $this->migration("Schema::create('users', function (Blueprint \$table) { \$table->id(); });");
        $this->artisan('easyerd:generate', ['--check' => true])->assertFailed();
        $this->assertSame($original, File::get($path));

        $this->artisan('easyerd:generate', ['--type' => 'mermaid'])->assertSuccessful();
        $this->artisan('easyerd:generate', ['--check' => true])->assertSuccessful();
    }

    public function test_mermaid_accepts_a_fixed_output_file_and_raw_mmd(): void
    {
        $path = $this->directory.'/custom/schema.mmd';
        $this->artisan('easyerd:generate', ['--type' => 'mermaid', '--output' => $path])->assertSuccessful();
        $this->assertSame("erDiagram\n", File::get($path));
        $this->artisan('easyerd:generate', ['--check' => true, '--output' => $path])->assertSuccessful();
    }

    public function test_command_applies_filters_without_leaking_config_to_the_next_run(): void
    {
        $this->migration(<<<'PHP'
Schema::create('users', function (Blueprint $table) { $table->id(); });
Schema::create('posts', function (Blueprint $table) { $table->foreignId('user_id'); });
Schema::create('comments', function (Blueprint $table) { $table->foreignId('post_id'); });
PHP);
        $path = $this->directory.'/docs/erd.md';
        $this->artisan('easyerd:generate', ['--type' => 'mermaid', '--tables' => ['users,posts'], '--exclude' => ['posts']])->assertSuccessful();
        $this->assertStringContainsString('users', File::get($path));
        $this->assertStringNotContainsString('posts', File::get($path));

        $this->artisan('easyerd:generate', ['--type' => 'mermaid', '--tables' => ['users'], '--depth' => '1'])->assertSuccessful();
        $this->assertStringContainsString('posts', File::get($path));
        $this->assertStringNotContainsString('comments', File::get($path));

        $this->artisan('easyerd:generate', ['--type' => 'mermaid'])->assertSuccessful();
        $this->assertStringContainsString('comments', File::get($path));
    }

    public function test_invalid_options_fail_without_writing_output(): void
    {
        foreach ([['--type' => 'invalid'], ['--source' => 'invalid'], ['--type' => 'mermaid', '--depth' => '-1'], ['--type' => 'mermaid', '--depth' => 'nope'], ['--type' => 'text', '--check' => true]] as $options) {
            $this->artisan('easyerd:generate', $options)->assertFailed();
        }
        $this->assertFileDoesNotExist($this->directory.'/docs/erd.md');
    }

    public function test_text_command_is_registered_and_writes_output_without_graphviz(): void
    {
        $this->migration(<<<'PHP'
Schema::create('users', function (Blueprint $table) {
    $table->string('name');
});
PHP);

        $this->artisan('easyerd:generate', ['--type' => 'text'])->assertSuccessful();

        $files = File::files($this->directory.'/output');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('TABLE: users', File::get($files[0]));
    }
}

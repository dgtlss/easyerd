<?php

namespace Dgtlss\EasyErd\Tests;

use Illuminate\Support\Facades\File;

class MermaidTest extends TestCase
{
    public function test_mermaid_is_deterministic_and_shows_keys_and_relationships(): void
    {
        $this->migration(<<<'PHP'
Schema::create('users', function (Blueprint $table) {
    $table->id();
});
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
});
PHP);

        $generator = $this->generator();
        $output = $generator->generateMermaid();

        $this->assertStringStartsWith("```mermaid\nerDiagram\n", $output);
        $this->assertStringContainsString('BIGINT id PK', $output);
        $this->assertStringContainsString('BIGINT user_id FK', $output);
        $this->assertStringContainsString('posts }o--|| users : "user_id"', $output);
        $this->assertSame($output, $generator->generateMermaid());
        $this->assertStringStartsWith('erDiagram', $generator->generateMermaid(false));
    }

    public function test_mermaid_command_needs_no_graphviz(): void
    {
        config(['easyerd.graphviz_path' => '/no/graphviz']);
        $this->artisan('easyerd:generate', ['--type' => 'mermaid'])->assertSuccessful();

        $this->assertStringContainsString('erDiagram', File::get($this->directory.'/docs/erd.md'));
    }
}

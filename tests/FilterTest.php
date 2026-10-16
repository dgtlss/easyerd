<?php

namespace Dgtlss\EasyErd\Tests;

class FilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migration(<<<'PHP'
Schema::create('users', function (Blueprint $table) { $table->id(); });
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
});
Schema::create('comments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('post_id')->constrained();
});
Schema::create('settings', function (Blueprint $table) { $table->id(); });
PHP);
    }

    public function test_table_selection_removes_dangling_edges(): void
    {
        $data = $this->generator(['tables' => ['users', 'posts']])->generate();

        $this->assertSame(['posts', 'users'], array_keys($data['tables']));
        $this->assertCount(1, $data['relationships']);
    }

    public function test_depth_walks_relationships_in_both_directions(): void
    {
        $this->assertSame(['users'], array_keys($this->generator(['tables' => ['users'], 'depth' => 0])->generate()['tables']));
        $this->assertSame(['posts', 'users'], array_keys($this->generator(['tables' => ['users'], 'depth' => 1])->generate()['tables']));
        $this->assertSame(['comments', 'posts', 'users'], array_keys($this->generator(['tables' => ['comments'], 'depth' => 2])->generate()['tables']));
    }

    public function test_excluded_tables_cannot_reappear_or_bridge_the_depth_walk(): void
    {
        $data = $this->generator(['tables' => ['users'], 'depth' => 3, 'excluded_tables' => ['posts']])->generate();

        $this->assertSame(['users'], array_keys($data['tables']));
        $this->assertSame([], $data['relationships']);

        $data = $this->generator(['excluded_tables' => ['users']])->generate();
        $this->assertArrayNotHasKey('users', $data['tables']);
        $this->assertCount(1, $data['relationships']);
    }

    public function test_repeated_generation_does_not_keep_old_tables_or_filters(): void
    {
        $generator = $this->generator();
        $this->assertCount(4, $generator->generate()['tables']);
        $generator->config['tables'] = ['users'];
        $this->assertSame(['users'], array_keys($generator->generate()['tables']));
        unlink(database_path('migrations/001_create_tables.php'));
        $generator->config['tables'] = [];
        $this->assertSame([], $generator->generate()['tables']);
    }

    public function test_depth_requires_starting_tables(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator(['depth' => 1])->generate();
    }
}

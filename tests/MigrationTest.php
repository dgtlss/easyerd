<?php

namespace Dgtlss\EasyErd\Tests;

use Illuminate\Support\Facades\DB;

class MigrationTest extends TestCase
{
    public function test_empty_project_needs_no_database(): void
    {
        DB::shouldReceive('connection')->never();

        $this->assertSame([], $this->generator()->generate()['tables']);
    }

    public function test_parses_columns_and_traditional_foreign_keys(): void
    {
        $this->migration(<<<'PHP'
Schema::create('users', function (Blueprint $table) {
    $table->string('name', 100);
});
Schema::create('posts', function (Blueprint $table) {
    $table->unsignedBigInteger('user_id');
    $table->foreign('user_id')->references('id')->on('users');
});
PHP);

        $data = $this->generator()->generate();

        $this->assertSame('VARCHAR(100)', $data['tables']['users']['columns'][0]['type']);
        $this->assertCount(1, $data['relationships']);
        $this->assertSame('users', $data['relationships'][0]['to_table']);
    }

    public function test_foreign_id_for_handles_the_fully_qualified_class_in_issue_one(): void
    {
        $this->migration(<<<'PHP'
Schema::create('projects', function (Blueprint $table) {
    $table->foreignIdFor(\App\Models\Organisation::class);
});
PHP);

        $data = $this->generator()->generate();

        $this->assertSame('organisation_id', $data['tables']['projects']['columns'][0]['name']);
        $this->assertSame('BIGINT', $data['tables']['projects']['columns'][0]['type']);
        $this->assertSame('organisations', $data['relationships'][0]['to_table']);
    }

    public function test_default_columns_and_nested_braces_do_not_truncate_tables(): void
    {
        $this->migration(<<<'PHP'
Schema::create("posts", function (Blueprint $table): void {
    $table->id();
    if (true) {
        $table->string('title')->default('}');
    }
    $table->timestamps();
    $table->softDeletes();
});
PHP);

        $columns = $this->generator()->generate()['tables']['posts']['columns'];

        $this->assertSame(['id', 'title', 'created_at', 'updated_at', 'deleted_at'], array_column($columns, 'name'));
        $this->assertTrue($columns[0]['primary']);
    }

    public function test_constrained_table_and_column_override_inference(): void
    {
        $this->migration(<<<'PHP'
Schema::create('posts', function (Blueprint $table) {
    $table->foreignId('author_id')->nullable()->constrained('users', 'uuid');
    $table->foreignIdFor(\App\Models\Organisation::class, 'owner_id')->constrained('accounts');
});
PHP);

        $relationships = $this->generator()->generate()['relationships'];

        $this->assertCount(2, $relationships);
        $this->assertSame(['users', 'accounts'], array_column($relationships, 'to_table'));
        $this->assertSame(['uuid', 'id'], array_column($relationships, 'to_column'));
        $this->assertSame(['author_id', 'owner_id'], array_column($relationships, 'from_column'));
    }

    public function test_reads_up_only_and_applies_later_migrations_from_subdirectories_in_filename_order(): void
    {
        $this->migration(<<<'PHP'
return new class {
    public function up(): void {
        Schema::create('posts', function (Blueprint $blueprint) {
            $blueprint->id();
            // $blueprint->string('commented_out');
            $blueprint->string('description')->default("text with ); } and ,");
        });
    }
    public function down(): void {
        Schema::create('rollback_only', function (Blueprint $table) { $table->id(); });
    }
};
PHP, '001_create_posts.php');
        mkdir(database_path('migrations/nested'));
        $this->migration(<<<'PHP'
Schema::table('posts', function (Blueprint $table) {
    $table->timestamps();
    $table->rememberToken();
    $table->index('description');
});
PHP, 'nested/002_update_posts.php');

        $data = $this->generator()->generate();

        $this->assertSame(['posts'], array_keys($data['tables']));
        $this->assertSame(['id', 'description', 'created_at', 'updated_at', 'remember_token'], array_column($data['tables']['posts']['columns'], 'name'));
    }

    public function test_composite_keys_and_named_foreign_constraints_do_not_become_columns(): void
    {
        $this->migration(<<<'PHP'
Schema::create('memberships', function (Blueprint $table) {
    $table->unsignedBigInteger('team_id');
    $table->unsignedBigInteger('user_id');
    $table->primary(['team_id', 'user_id'], 'membership_primary');
    $table->foreign(['team_id', 'user_id'], 'membership_fk')
        ->references(['team_id', 'id'])->on('users');
});
PHP);

        $data = $this->generator()->generate();
        $columns = $data['tables']['memberships']['columns'];

        $this->assertCount(2, $columns);
        $this->assertSame([true, true], array_column($columns, 'primary'));
        $this->assertSame([true, true], array_column($columns, 'foreign'));
        $this->assertSame(['team_id', 'id'], array_column($data['relationships'], 'to_column'));
    }

    public function test_explicit_constraints_replace_inferred_targets_and_support_named_arguments(): void
    {
        $this->migration(<<<'PHP'
use App\Models\{User, Organisation as Org};
Schema::create('posts', function (Blueprint $table) {
    $table->foreignId('author_id');
    $table->foreign('author_id', 'author_fk')->references('id')->on('users');
    $table->foreignIdFor(model: Org::class, column: 'owner_id')
        ->constrained(table: 'accounts', column: 'uuid');
    $table->foreignUuid('editor_uuid')->references('uuid')->on('people');
    $table->foreignIdFor(Org::class);
});
PHP);

        $data = $this->generator()->generate();

        $this->assertCount(4, $data['tables']['posts']['columns']);
        $this->assertCount(4, $data['relationships']);
        $this->assertSame(['users', 'people', 'organisations', 'accounts'], array_column($data['relationships'], 'to_table'));
        $this->assertSame(['id', 'uuid', 'id', 'uuid'], array_column($data['relationships'], 'to_column'));
    }
}

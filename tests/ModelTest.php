<?php

namespace Dgtlss\EasyErd\Tests;

class ModelTest extends TestCase
{
    public function test_typed_relationships_use_import_aliases_custom_tables_and_keys(): void
    {
        $this->model('Identity/Person', <<<'PHP'
namespace App\Models\Identity;
class Person {
    use HasUuids;
    protected $table = "people";
    protected $primaryKey = 'uuid';
    public function posts(): HasMany {
        $table = 'wrong_local_variable';
        return $this->hasMany(\App\Models\Post::class, 'author_uuid', 'uuid');
    }
}
PHP);
        $this->model('Post', <<<'PHP'
namespace App\Models;
use App\Models\Identity\Person as Author;
class Post {
    public function author(): BelongsTo {
        return $this->belongsTo(Author::class, 'author_uuid', 'uuid');
    }
}
PHP);
        $this->migration(<<<'PHP'
use App\Models\Identity\Person as Author;
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->foreignIdFor(Author::class, 'author_uuid')->constrained();
});
PHP);

        $data = $this->generator()->generate();

        $this->assertCount(1, $data['relationships']);
        $this->assertSame('people', $data['relationships'][0]['to_table']);
        $this->assertSame('uuid', $data['relationships'][0]['to_column']);
        $this->assertSame('UUID', $data['tables']['posts']['columns'][1]['type']);
        $this->assertStringContainsString('posts }o--|| people : "author"', $this->generator()->generateMermaid());
    }

    public function test_model_only_diagrams_include_each_supported_cardinality(): void
    {
        $this->model('User', <<<'PHP'
namespace App\Models;
class User {
    public function posts(): HasMany { return $this->hasMany(Post::class); }
    public function profile(): HasOne { return $this->hasOne(Profile::class); }
    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function displayName(): string { return $this->getAttribute('name'); }
}
PHP);

        $generator = $this->generator(['include_migrations' => false]);
        $data = $generator->generate();

        $this->assertCount(4, $data['relationships']);
        $mermaid = $generator->generateMermaid();
        $this->assertStringContainsString('users ||--o{ posts : "posts"', $mermaid);
        $this->assertStringContainsString('users ||--o| profiles : "profile"', $mermaid);
        $this->assertStringContainsString('users }o--o{ roles : "roles"', $mermaid);
        $this->assertStringContainsString('users }o--|| accounts : "account"', $mermaid);
        $this->assertStringContainsString('users <- posts', $generator->generateText());
    }

    public function test_model_scanning_can_be_disabled_and_does_not_execute_model_files(): void
    {
        $this->model('User', <<<'PHP'
namespace App\Models;
throw new \RuntimeException('Models must never be loaded');
class User {
    public function posts() { return $this->hasMany(Post::class); }
}
PHP);

        $this->assertCount(1, $this->generator()->generate()['relationships']);
        $this->assertSame([], $this->generator(['include_models' => false])->generate()['models']);
    }
}

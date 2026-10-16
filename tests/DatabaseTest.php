<?php

namespace Dgtlss\EasyErd\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseTest extends TestCase
{
    public function test_database_source_reads_actual_columns_keys_and_foreign_keys(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('author_uuid')->nullable()->references('uuid')->on('accounts');
        });
        $this->migration("Schema::create('ignored_migration', function (Blueprint \$table) { \$table->id(); });");

        $data = $this->generator(['source' => 'database'])->generate();

        $this->assertSame(['accounts', 'posts'], array_keys($data['tables']));
        $this->assertTrue($data['tables']['accounts']['columns'][0]['primary']);
        $this->assertTrue($data['tables']['posts']['columns'][1]['foreign']);
        $this->assertTrue($data['tables']['posts']['columns'][1]['nullable']);
        $this->assertSame('accounts', $data['relationships'][0]['to_table']);
        $this->assertSame('uuid', $data['relationships'][0]['to_column']);
        $this->assertStringContainsString('posts }o--o| accounts', $this->generator(['source' => 'database'])->generateMermaid());
    }

    public function test_database_source_honors_named_connections_and_prefixes(): void
    {
        config(['database.connections.reporting' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'report_',
        ]]);
        $schema = DB::connection('reporting')->getSchemaBuilder();
        $schema->create('users', fn (Blueprint $table) => $table->id());
        $schema->create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });

        $data = $this->generator(['source' => 'database', 'connection' => 'reporting'])->generate();

        $this->assertSame(['posts', 'users'], array_keys($data['tables']));
        $this->assertCount(2, $data['tables']['posts']['columns']);
        $this->assertSame('users', $data['relationships'][0]['to_table']);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->generator(['source' => 'invalid'])->generate();
    }
}

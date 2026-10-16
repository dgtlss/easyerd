<?php

namespace Dgtlss\EasyErd\Tests;

use Dgtlss\EasyErd\EasyErdServiceProvider;
use Dgtlss\EasyErd\Services\ErdGenerator;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $directory;

    protected function getPackageProviders($app)
    {
        return [EasyErdServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/easyerd-tests-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->directory.'/database/migrations', 0755, true);
        File::makeDirectory($this->directory.'/app/Models', 0755, true);
        $this->app->useDatabasePath($this->directory.'/database');
        $this->app->useAppPath($this->directory.'/app');
        config([
            'easyerd.output_path' => $this->directory.'/output',
            'easyerd.mermaid_path' => $this->directory.'/docs/erd.md',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    protected function migration(string $source, string $name = '001_create_tables.php'): void
    {
        File::put(database_path('migrations/'.$name), "<?php\n".$source);
    }

    protected function model(string $name, string $source): void
    {
        $path = app_path('Models/'.$name.'.php');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n".$source);
    }

    protected function generator(array $config = []): ErdGenerator
    {
        return new ErdGenerator(array_replace(config('easyerd'), $config));
    }
}

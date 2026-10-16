<?php

namespace Dgtlss\EasyErd\Console;

use Dgtlss\EasyErd\Services\ErdGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateErdCommand extends Command
{
    protected $signature = 'easyerd:generate
                           {--type=both : Output type (image, text, both, mermaid)}
                           {--output= : Output directory, or a .md/.mmd file for Mermaid}
                           {--format= : Image format (png, svg, jpg, pdf)}
                           {--source= : Schema source (migrations or database)}
                           {--connection= : Database connection for --source=database}
                           {--tables=* : Tables to include (repeat or comma-separate)}
                           {--exclude=* : Tables to exclude (repeat or comma-separate)}
                           {--depth= : Relationship hops from --tables, starting at zero}
                           {--check : Fail if the Mermaid file is missing or out of date; write nothing}
                           {--width= : Image width in pixels}
                           {--height= : Image height in pixels}
                           {--dpi= : DPI for image output}
                           {--font-size= : Font size in points}
                           {--nodesep= : Node separation in graph units}
                           {--ranksep= : Rank separation in graph units}';

    protected $description = 'Generate ERD diagrams from migrations or a live database, with model relationships';

    public function __construct(protected ErdGenerator $erdGenerator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $originalConfig = $this->erdGenerator->config;

        try {
            $type = $this->option('type');
            if (!in_array($type, ['image', 'text', 'both', 'mermaid'])) {
                throw new \InvalidArgumentException('Type must be image, text, both, or mermaid.');
            }
            if ($this->option('check')) {
                if (!in_array($type, ['both', 'mermaid'])) {
                    throw new \InvalidArgumentException('--check requires Mermaid output.');
                }
                $type = 'mermaid';
            }

            foreach (['source' => 'source', 'connection' => 'connection', 'depth' => 'depth', 'font-size' => 'font_size', 'nodesep' => 'nodesep', 'ranksep' => 'ranksep'] as $option => $key) {
                if ($this->option($option) !== null) {
                    $this->erdGenerator->config[$key] = $this->option($option);
                }
            }
            if ($this->option('tables')) {
                $this->erdGenerator->config['tables'] = $this->tableList($this->option('tables'));
            }
            $this->erdGenerator->config['excluded_tables'] = array_values(array_unique(array_merge(
                $originalConfig['excluded_tables'] ?? [],
                $this->tableList($this->option('exclude')),
            )));

            if ($type === 'mermaid') {
                return $this->generateMermaid();
            }

            $output = $this->option('output') ?? $originalConfig['output_path'] ?? storage_path('app/erd');
            if (in_array($type, ['image', 'both'])) {
                $this->generateImage($output);
            }
            if (in_array($type, ['text', 'both'])) {
                $this->generateText($output);
            }
            $this->info('ERD generated successfully!');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Error generating ERD: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $this->erdGenerator->config = $originalConfig;
        }
    }

    protected function tableList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', implode(',', $values))), fn ($value) => $value !== '')));
    }

    protected function generateImage(string $output): void
    {
        $format = $this->option('format') ?? $this->erdGenerator->config['image_format'] ?? 'pdf';
        if (!in_array($format, ['png', 'svg', 'jpg', 'jpeg', 'pdf'])) {
            throw new \InvalidArgumentException('Image format must be png, svg, jpg, jpeg, or pdf.');
        }
        $filepath = $this->timestampedPath($output, $format);
        File::ensureDirectoryExists($output);
        $this->erdGenerator->generateImage($filepath, $format, $this->option('width'), $this->option('height'), $this->option('dpi'));
        $this->info("Image ERD saved to: {$filepath}");
    }

    protected function generateText(string $output): void
    {
        $text = $this->erdGenerator->generateText();
        $filepath = $this->timestampedPath($output, 'txt');
        $this->writeOutput($filepath, $text);
        $this->info("Text ERD saved to: {$filepath}");
        if ($this->output->isVerbose()) {
            $this->line($text);
        }
    }

    protected function timestampedPath(string $output, string $extension): string
    {
        $appname = Str::slug(config('app.name', 'laravel'));

        return rtrim($output, '/').'/'.$appname.'-erd-'.now()->format('YmdHis').'.'.$extension;
    }

    protected function generateMermaid(): int
    {
        $path = $this->option('output') ?? $this->erdGenerator->config['mermaid_path'] ?? base_path('docs/erd.md');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, ['md', 'mmd'])) {
            $path = rtrim($path, '/').'/erd.md';
        }
        $content = $this->erdGenerator->generateMermaid($extension !== 'mmd');

        if ($this->option('check')) {
            if (!File::isFile($path) || File::get($path) !== $content) {
                $this->error("ERD is missing or out of date: {$path}");
                $this->line('Regenerate it with easyerd:generate --type=mermaid using the same source and filters.');

                return self::FAILURE;
            }
            $this->info("ERD is up to date: {$path}");

            return self::SUCCESS;
        }

        $this->writeOutput($path, $content);
        $this->info("Mermaid ERD saved to: {$path}");

        return self::SUCCESS;
    }

    protected function writeOutput(string $path, string $content): void
    {
        File::ensureDirectoryExists(dirname($path));
        if (File::put($path, $content) === false) {
            throw new \RuntimeException("Could not write ERD to {$path}.");
        }
    }
}

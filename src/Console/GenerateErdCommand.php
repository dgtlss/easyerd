<?php

namespace Dgtlss\EasyErd\Console;

use Illuminate\Console\Command;
use Dgtlss\EasyErd\Services\ErdGenerator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class GenerateErdCommand extends Command
{
    protected $signature = 'easyerd:generate 
                           {--type=both : Type of ERD to generate (image, text, both)}
                           {--output= : Custom output path for the ERD files}
                           {--format=png : Image format (png, svg, jpg, pdf)}
                           {--width=4000 : Image width for pixel-based formats}
                           {--height=3000 : Image height for pixel-based formats}
                           {--dpi=300 : DPI for image output}
                           {--font-size=12 : Font size in points}
                           {--nodesep=1.2 : Node separation in graph units}
                           {--ranksep=1.8 : Rank separation in graph units}';
    
    protected $description = 'Generate ERD diagrams from your Laravel models and migrations';

    protected $erdGenerator;

    public function __construct(ErdGenerator $erdGenerator)
    {
        parent::__construct();
        $this->erdGenerator = $erdGenerator;
    }

    public function handle()
    {
        $type = $this->option('type');
        $output = $this->option('output') ?? config('easyerd.output_path', storage_path('app/erd'));
        $format = $this->option('format');

        $this->info('Generating ERD...');

        if (!File::exists($output)) {
            File::makeDirectory($output, 0755, true);
        }

        // Update the ERD generator config with quality options
        $config = [
            'font_size' => $this->option('font-size') ?? config('easyerd.font_size', 12),
            'nodesep' => $this->option('nodesep') ?? config('easyerd.nodesep', 1.2),
            'ranksep' => $this->option('ranksep') ?? config('easyerd.ranksep', 1.8),
        ];
        
        $this->erdGenerator->config = array_merge($this->erdGenerator->config ?? [], $config);

        try {
            switch ($type) {
                case 'image':
                    $this->generateImage($output, $format);
                    break;
                case 'text':
                    $this->generateText($output);
                    break;
                case 'both':
                default:
                    $this->generateImage($output, $format);
                    $this->generateText($output);
                    break;
            }

            $this->info('ERD generated successfully!');
            $this->info("Output directory: {$output}");

        } catch (\Exception $e) {
            $this->error('Error generating ERD: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    protected function generateImage($output, $format)
    {
        $this->info('Generating image ERD...');

        $appname = Str::slug(config('app.name'));
        $timestamp = now()->format('YmdHis');
        
        $format = $format ?? config('easyerd.image_format', 'svg');
        $filename = "{$appname}-erd-{$timestamp}.{$format}";
        $filepath = "{$output}/{$filename}";
        
        // Get the new quality options
        $width = $this->option('width') ?? config('easyerd.image_width', 4000);
        $height = $this->option('height') ?? config('easyerd.image_height', 3000);
        $dpi = $this->option('dpi') ?? config('easyerd.dpi', 300);
        
        $this->erdGenerator->generateImage($filepath, $format, $width, $height, $dpi);
        
        $this->info("Image ERD saved to: {$filepath}");
    }

    protected function generateText($output)
    {
        $this->info('Generating text ERD...');
        
        $textErd = $this->erdGenerator->generateText();
        $appname = Str::slug(config('app.name'));
        $timestamp = now()->format('YmdHis');
        $filepath = "{$output}/{$appname}-erd-{$timestamp}.txt";
        
        File::put($filepath, $textErd);
        
        $this->info("Text ERD saved to: {$filepath}");
        
        if ($this->option('verbose')) {
            $this->line("\n" . $textErd);
        }
    }
}
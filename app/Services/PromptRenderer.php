<?php

namespace App\Services;

use RuntimeException;

class PromptRenderer
{
    public const TEMPLATE_RELATIVE_PATH = 'app/prompts/currency_analysis.md';

    public function render(array $vars): string
    {
        $template = $this->getRaw();

        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }

        return $template;
    }

    public function getRaw(): string
    {
        $path = $this->absolutePath();

        if (! is_file($path)) {
            throw new RuntimeException("Prompt template missing: {$path}");
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Prompt template unreadable: {$path}");
        }

        return $content;
    }

    public function save(string $content): void
    {
        $path = $this->absolutePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    public function absolutePath(): string
    {
        return storage_path(self::TEMPLATE_RELATIVE_PATH);
    }
}

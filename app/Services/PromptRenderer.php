<?php

namespace App\Services;

use RuntimeException;

/**
 * Renders the AI-analysis prompt template by substituting {{var}} placeholders.
 *
 * Lookup order:
 *   1. storage/app/prompts/currency_analysis.md   (admin-editable, persists)
 *   2. resources/prompts/currency_analysis.md     (ships with the repo)
 *
 * Why two paths:
 *   storage/app/ is .gitignored, so a prompt only living there is lost on
 *   a fresh `git pull` deploy. Keeping a default in resources/ guarantees
 *   the file is always present after a deploy. Admin edits go to storage/
 *   so customisations win over the shipped default.
 */
class PromptRenderer
{
    public const STORAGE_RELATIVE_PATH   = 'app/prompts/currency_analysis.md';
    public const RESOURCES_RELATIVE_PATH = 'prompts/currency_analysis.md';

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
            throw new RuntimeException(
                "Prompt template missing. Checked:\n"
                . "  - " . $this->storagePath() . "\n"
                . "  - " . $this->resourcesPath()
            );
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Prompt template unreadable: {$path}");
        }

        return $content;
    }

    /**
     * Save an admin-edited prompt. Always writes to the storage path so the
     * shipped default in resources/ is never modified in-place.
     */
    public function save(string $content): void
    {
        $path = $this->storagePath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    /** Returns whichever path currently exists (storage wins if both do). */
    public function absolutePath(): string
    {
        $storagePath = $this->storagePath();
        if (is_file($storagePath)) {
            return $storagePath;
        }
        return $this->resourcesPath();
    }

    public function storagePath(): string
    {
        return storage_path(self::STORAGE_RELATIVE_PATH);
    }

    public function resourcesPath(): string
    {
        return resource_path(self::RESOURCES_RELATIVE_PATH);
    }
}

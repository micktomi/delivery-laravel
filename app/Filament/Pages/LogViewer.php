<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class LogViewer extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Logs';

    protected static ?string $title = 'Application logs';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.log-viewer';

    public string $source = 'application';

    public string $search = '';

    private const MAX_BYTES = 262144;

    private const MAX_LINES = 500;

    private const READ_CHUNK_BYTES = 8192;

    /** @var array<string, array{label: string, prefix: string}> */
    private const SOURCES = [
        'application' => [
            'label' => 'Laravel / Application',
            'prefix' => 'laravel',
        ],
        'payments' => [
            'label' => 'Payments / Viva',
            'prefix' => 'payments',
        ],
    ];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_admin === true;
    }

    public function selectSource(string $source): void
    {
        if (! array_key_exists($source, self::SOURCES)) {
            return;
        }

        $this->source = $source;
        $this->reset('search');
    }

    /**
     * @return array{label: string, filename: ?string, lines: list<string>, limited: bool}
     */
    public function getLogSnapshot(): array
    {
        $source = self::SOURCES[$this->source] ?? self::SOURCES['application'];
        $path = $this->latestWhitelistedFile($source['prefix']);

        if ($path === null) {
            return [
                'label' => $source['label'],
                'filename' => null,
                'lines' => [],
                'limited' => false,
            ];
        }

        $tail = $this->tail($path);
        $search = substr(trim($this->search), 0, 100);
        $lines = $tail['lines'];

        if ($search !== '') {
            $lines = array_values(array_filter(
                $lines,
                fn (string $line): bool => stripos($line, $search) !== false,
            ));
        }

        return [
            'label' => $source['label'],
            'filename' => basename($path),
            'lines' => $lines,
            'limited' => $tail['limited'],
        ];
    }

    private function latestWhitelistedFile(string $prefix): ?string
    {
        $directory = realpath(storage_path('logs'));

        if ($directory === false) {
            return null;
        }

        $pattern = '/\A'.preg_quote($prefix, '/').'(?:-\d{4}-\d{2}-\d{2})?\.log\z/D';
        $matches = glob($directory.'/'.$prefix.'*.log', GLOB_NOSORT) ?: [];
        $latest = null;
        $latestModifiedAt = -1;

        foreach ($matches as $match) {
            if (! preg_match($pattern, basename($match))) {
                continue;
            }

            $realPath = realpath($match);

            if ($realPath === false
                || dirname($realPath) !== $directory
                || ! is_file($realPath)
                || ! is_readable($realPath)) {
                continue;
            }

            $modifiedAt = filemtime($realPath);

            if ($modifiedAt !== false && $modifiedAt >= $latestModifiedAt) {
                $latest = $realPath;
                $latestModifiedAt = $modifiedAt;
            }
        }

        return $latest;
    }

    /** @return array{lines: list<string>, limited: bool} */
    private function tail(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return ['lines' => [], 'limited' => false];
        }

        $buffer = '';
        $bytesRead = 0;
        $position = 0;

        try {
            if (fseek($handle, 0, SEEK_END) !== 0 || ($position = ftell($handle)) === false) {
                return ['lines' => [], 'limited' => false];
            }

            while ($position > 0
                && $bytesRead < self::MAX_BYTES
                && substr_count($buffer, "\n") <= self::MAX_LINES) {
                $length = min(self::READ_CHUNK_BYTES, $position, self::MAX_BYTES - $bytesRead);
                $position -= $length;

                if (fseek($handle, $position, SEEK_SET) !== 0) {
                    break;
                }

                $chunk = fread($handle, $length);

                if ($chunk === false) {
                    break;
                }

                $buffer = $chunk.$buffer;
                $bytesRead += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        $limited = $position > 0;

        if ($limited && ($firstNewline = strpos($buffer, "\n")) !== false) {
            $buffer = substr($buffer, $firstNewline + 1);
        }

        $buffer = rtrim($buffer, "\r\n");
        $lines = $buffer === '' ? [] : (preg_split('/\r\n|\n|\r/', $buffer) ?: []);

        if (count($lines) > self::MAX_LINES) {
            $limited = true;
            $lines = array_slice($lines, -self::MAX_LINES);
        }

        return ['lines' => array_values($lines), 'limited' => $limited];
    }
}

<?php

namespace BillTo\PrestaShop\Support;

/**
 * Stores invoice PDFs inside the module's pdf/ directory (direct access blocked by .htaccess).
 */
final class PdfStorage
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function ensureDirectory(): string
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0755, true);
        }

        if (!file_exists($this->directory . '/.htaccess')) {
            @file_put_contents($this->directory . '/.htaccess', "Order deny,allow\nDeny from all\n");
        }

        if (!file_exists($this->directory . '/index.php')) {
            @file_put_contents($this->directory . '/index.php', "<?php\nheader('HTTP/1.1 403 Forbidden');\nexit;\n");
        }

        return $this->directory;
    }

    public function store(int $idOrder, string $suffix, string $content): string
    {
        $dir = $this->ensureDirectory();
        $name = sprintf('%d-%s-%s.pdf', $idOrder, preg_replace('/[^a-z0-9_-]/i', '-', $suffix), substr(sha1($idOrder . $suffix . _COOKIE_KEY_), 0, 12));
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function exists(string $path): bool
    {
        return $path !== '' && strpos($path, $this->directory) === 0 && is_file($path);
    }
}

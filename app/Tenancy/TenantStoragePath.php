<?php

namespace App\Tenancy;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;

final readonly class TenantStoragePath
{
    private const DISK = 'local';

    private const ROOT = 'tenants';

    public function __construct(
        private TenantContext $tenant,
        private FilesystemManager $filesystems,
    ) {}

    public function path(string $relativePath): string
    {
        if (! $this->isSafeRelativePath($relativePath)) {
            throw new InvalidArgumentException('Tenant storage paths must be safe relative paths.');
        }

        return self::ROOT.'/'.$this->tenant->id().'/'.$relativePath;
    }

    public function store(UploadedFile $file, string $directory): string
    {
        $directory = $this->validatedDirectory($directory);
        $storedPath = $this->disk()->putFile($this->path($directory), $file);

        if (! is_string($storedPath)) {
            throw new RuntimeException('Unable to store the tenant file.');
        }

        return $storedPath;
    }

    public function readablePath(?string $storedPath, string $legacyDirectory): ?string
    {
        if (! is_string($storedPath) || ! $this->isSafeRelativePath($storedPath)) {
            return null;
        }

        $legacyDirectory = $this->validatedDirectory($legacyDirectory);

        if ($this->isCurrentTenantPath($storedPath, $legacyDirectory)) {
            return $this->disk()->exists($storedPath) ? $storedPath : null;
        }

        if (str_starts_with($storedPath, self::ROOT.'/')
            || ! $this->isWithinDirectory($storedPath, $legacyDirectory)) {
            return null;
        }

        $migratedPath = $this->path($storedPath);

        if ($this->disk()->exists($migratedPath)) {
            return $migratedPath;
        }

        return null;
    }

    public function delete(?string $storedPath, string $legacyDirectory): bool
    {
        if (! is_string($storedPath) || ! $this->isSafeRelativePath($storedPath)) {
            return false;
        }

        $legacyDirectory = $this->validatedDirectory($legacyDirectory);

        return $this->isCurrentTenantPath($storedPath, $legacyDirectory)
            && $this->disk()->delete($storedPath);
    }

    private function disk(): FilesystemAdapter
    {
        return $this->filesystems->disk(self::DISK);
    }

    private function validatedDirectory(string $directory): string
    {
        $directory = trim($directory, '/');

        if (! $this->isSafeRelativePath($directory)) {
            throw new InvalidArgumentException('Tenant storage directories must be safe relative paths.');
        }

        return $directory;
    }

    private function isCurrentTenantPath(string $path, string $directory): bool
    {
        return $this->isWithinDirectory($path, $this->path($directory));
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        return str_starts_with($path, $directory.'/');
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, "\0")) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Persists editable portal branding (texts + public media) for IT.
 */
class BrandingService
{
    public function path(): string
    {
        return storage_path('app/branding.json');
    }

    /**
     * @return array{
     *   system_name: string,
     *   system_short_name: string,
     *   tagline: string,
     *   login_heading: string,
     *   login_subtitle: string,
     *   footer_text: string,
     *   logo_path: string|null,
     *   favicon_path: string|null,
     *   login_bg_path: string|null
     * }
     */
    public function get(): array
    {
        $defaults = $this->defaults();
        $path = $this->path();

        if (! is_file($path)) {
            return $defaults;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        return $this->normalize(array_merge($defaults, $decoded));
    }

    /**
     * Public-facing payload with absolute media URLs.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $data = $this->get();

        return [
            'system_name' => $data['system_name'],
            'system_short_name' => $data['system_short_name'],
            'tagline' => $data['tagline'],
            'login_heading' => $data['login_heading'],
            'login_subtitle' => $data['login_subtitle'],
            'footer_text' => $data['footer_text'],
            'logo_url' => $this->publicUrl($data['logo_path']),
            'favicon_url' => $this->publicUrl($data['favicon_path']),
            'login_bg_url' => $this->publicUrl($data['login_bg_path']),
            'has_custom_logo' => $data['logo_path'] !== null,
            'has_custom_favicon' => $data['favicon_path'] !== null,
            'has_custom_login_bg' => $data['login_bg_path'] !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveTexts(array $input): array
    {
        $current = $this->get();
        $merged = $this->normalize(array_merge($current, $input));
        // Never wipe media paths via text save.
        $merged['logo_path'] = $current['logo_path'];
        $merged['favicon_path'] = $current['favicon_path'];
        $merged['login_bg_path'] = $current['login_bg_path'];

        $this->write($merged);

        return $this->publicPayload();
    }

    public function storeLogo(UploadedFile $file): array
    {
        return $this->storeMedia($file, 'logo_path', 'logo');
    }

    public function storeFavicon(UploadedFile $file): array
    {
        return $this->storeMedia($file, 'favicon_path', 'favicon');
    }

    public function storeLoginBackground(UploadedFile $file): array
    {
        return $this->storeMedia($file, 'login_bg_path', 'login-bg');
    }

    public function clearLogo(): array
    {
        return $this->clearMedia('logo_path');
    }

    public function clearFavicon(): array
    {
        return $this->clearMedia('favicon_path');
    }

    public function clearLoginBackground(): array
    {
        return $this->clearMedia('login_bg_path');
    }

    /**
     * Reset texts and remove custom media.
     *
     * @return array<string, mixed>
     */
    public function resetToDefaults(): array
    {
        $current = $this->get();
        foreach (['logo_path', 'favicon_path', 'login_bg_path'] as $key) {
            $this->deleteStoredFile($current[$key] ?? null);
        }
        $this->write($this->defaults());

        return $this->publicPayload();
    }

    /**
     * @return array{
     *   system_name: string,
     *   system_short_name: string,
     *   tagline: string,
     *   login_heading: string,
     *   login_subtitle: string,
     *   footer_text: string,
     *   logo_path: string|null,
     *   favicon_path: string|null,
     *   login_bg_path: string|null
     * }
     */
    public function defaults(): array
    {
        return [
            'system_name' => 'West Prime Horizon Institute, Inc.',
            'system_short_name' => 'West Prime Portal',
            'tagline' => 'Digital Academic Portal',
            'login_heading' => 'Welcome back',
            'login_subtitle' => 'Enter your institutional credentials to continue.',
            'footer_text' => 'West Prime Horizon Institute, Inc.',
            'logo_path' => null,
            'favicon_path' => null,
            'login_bg_path' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data): void
    {
        $dir = dirname($this->path());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->path(),
            json_encode($this->normalize($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *   system_name: string,
     *   system_short_name: string,
     *   tagline: string,
     *   login_heading: string,
     *   login_subtitle: string,
     *   footer_text: string,
     *   logo_path: string|null,
     *   favicon_path: string|null,
     *   login_bg_path: string|null
     * }
     */
    private function normalize(array $data): array
    {
        $defaults = $this->defaults();

        return [
            'system_name' => $this->trimText($data['system_name'] ?? null, $defaults['system_name'], 120),
            'system_short_name' => $this->trimText($data['system_short_name'] ?? null, $defaults['system_short_name'], 80),
            'tagline' => $this->trimText($data['tagline'] ?? null, $defaults['tagline'], 160),
            'login_heading' => $this->trimText($data['login_heading'] ?? null, $defaults['login_heading'], 120),
            'login_subtitle' => $this->trimText($data['login_subtitle'] ?? null, $defaults['login_subtitle'], 240),
            'footer_text' => $this->trimText($data['footer_text'] ?? null, $defaults['footer_text'], 160),
            'logo_path' => $this->nullablePath($data['logo_path'] ?? null),
            'favicon_path' => $this->nullablePath($data['favicon_path'] ?? null),
            'login_bg_path' => $this->nullablePath($data['login_bg_path'] ?? null),
        ];
    }

    private function trimText(mixed $value, string $fallback, int $max): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return $fallback;
        }

        return mb_substr($text, 0, $max);
    }

    private function nullablePath(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', trim($value)), '/');
        if (! str_starts_with($path, 'branding/')) {
            return null;
        }

        return $path;
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function storeMedia(UploadedFile $file, string $pathKey, string $basename): array
    {
        $current = $this->get();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $filename = $basename.'-'.now()->format('YmdHis').'.'.$extension;
        $newPath = $file->storeAs('branding', $filename, 'public');

        $this->deleteStoredFile($current[$pathKey] ?? null);
        $current[$pathKey] = $newPath;
        $this->write($current);

        return $this->publicPayload();
    }

    /**
     * @return array<string, mixed>
     */
    private function clearMedia(string $pathKey): array
    {
        $current = $this->get();
        $this->deleteStoredFile($current[$pathKey] ?? null);
        $current[$pathKey] = null;
        $this->write($current);

        return $this->publicPayload();
    }

    private function deleteStoredFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}

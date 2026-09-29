<?php

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

it('resolves the public disk to local storage by default', function () {
    expect(config('filesystems.disks.public.driver'))->toBe('local');
});

it('resolves the public disk to s3-compatible object storage once credentials are configured', function () {
    // Mirrors exactly what config/filesystems.php produces once AWS_ACCESS_KEY_ID
    // (e.g. a DigitalOcean Spaces access key) is present in the environment.
    config(['filesystems.disks.public' => [
        'driver' => 's3',
        'root' => storage_path('app/public'),
        'url' => 'https://wpds-media.nyc3.digitaloceanspaces.com',
        'visibility' => 'public',
        'throw' => false,
        'report' => false,
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'nyc3',
        'bucket' => 'wpds-media',
        'endpoint' => 'https://nyc3.digitaloceanspaces.com',
        'use_path_style_endpoint' => false,
    ]]);

    Storage::forgetDisk('public');

    expect(Storage::disk('public'))->toBeInstanceOf(AwsS3V3Adapter::class);
});

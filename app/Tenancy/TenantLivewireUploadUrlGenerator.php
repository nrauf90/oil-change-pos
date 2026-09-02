<?php

namespace App\Tenancy;

use BackedEnum;
use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

final class TenantLivewireUploadUrlGenerator extends GenerateSignedUploadUrl
{
    public const CENTRAL_CLAIM = 'central';

    public const TENANT_CLAIM = 'tenant_shop_id';

    public function __construct(
        private readonly GenerateSignedUploadUrl $generator,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function forS3(mixed $file, mixed $visibility = 'private'): array
    {
        return $this->generator->forS3($file, $visibility);
    }

    /**
     * @param  BackedEnum|string  $name
     * @param  DateTimeInterface|DateInterval|int  $expiration
     * @param  array<string, mixed>  $parameters
     */
    public function signedRoute(mixed $name, mixed $expiration, mixed $parameters = []): string
    {
        if ((! is_string($name) && ! $name instanceof BackedEnum)
            || (! $expiration instanceof DateTimeInterface
                && ! $expiration instanceof DateInterval
                && ! is_int($expiration))
            || ! is_array($parameters)) {
            throw new InvalidArgumentException('Invalid signed upload URL parameters.');
        }

        $parameters[self::TENANT_CLAIM] = $this->context->initialized()
            ? $this->context->id()
            : self::CENTRAL_CLAIM;

        return $this->generator->signedRoute($name, $expiration, $parameters);
    }
}

<?php

declare(strict_types=1);

namespace ExeQue\Monocle\Support\Masks;

/**
 * Masks the credentials of an Authorization header but keeps a known scheme
 * readable: "Bearer eyJh****". Values without a known scheme are masked as a
 * whole, like PartialMask does.
 */
final class AuthorizationMask
{
    private readonly PartialMask $partial;

    /**
     * @param  list<string>  $schemes  matched case-insensitively
     */
    public function __construct(
        private readonly array $schemes,
        int $visible = 4,
    ) {
        $this->partial = new PartialMask($visible);
    }

    public function __invoke(mixed $value, string $key): mixed
    {
        if (! is_string($value) || ! str_contains($value, ' ')) {
            return ($this->partial)($value, $key);
        }

        [$scheme, $credentials] = explode(' ', $value, 2);

        foreach ($this->schemes as $known) {
            if (strcasecmp($scheme, $known) === 0) {
                return $scheme.' '.($this->partial)(ltrim($credentials), $key);
            }
        }

        return ($this->partial)($value, $key);
    }
}

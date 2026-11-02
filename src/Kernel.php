<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Restricts the environments this kernel accepts to boot in: an unexpected
     * APP_ENV value must fail loudly instead of silently running with dev config.
     *
     * Declared protected (the trait declares it private) so that static analysis can
     * see it as an override rather than an unused private method.
     *
     * @return list<string> An array of allowed values for APP_ENV
     */
    protected function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}

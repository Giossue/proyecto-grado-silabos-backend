<?php

namespace Tests\Architecture;

use Tests\TestCase;

class TrustedProxiesDefaultTest extends TestCase
{
    public function test_without_the_variable_only_localhost_and_private_networks_are_trusted(): void
    {
        $config = file_get_contents(base_path('config/app.php'));

        $this->assertStringContainsString("env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')", (string) $config);
        $this->assertStringNotContainsString("env('TRUSTED_PROXIES', '*')", (string) $config);
    }
}

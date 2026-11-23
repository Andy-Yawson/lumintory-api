<?php

namespace Tests\Feature;

use Tests\TestCase;

class ZinnvyRiderClientTest extends TestCase
{
    public function test_the_rider_app_signs_in_with_its_own_client_when_one_is_configured(): void
    {
        config(['zinnvy.client_id' => 'zinnvy-inventory', 'zinnvy.client_secret' => 's1', 'zinnvy.accounts_url' => 'https://accounts.test']);
        $client = fn (string $q) => (parse_str(parse_url($this->get('/api/v1/auth/zinnvy/redirect'.$q)->headers->get('Location'), PHP_URL_QUERY), $out) ?: $out)['client_id'];

        $this->assertSame('zinnvy-inventory', $client('?app=rider'), 'falls back to the main client');

        config(['zinnvy.rider_client_id' => 'zinnvy-rider', 'zinnvy.rider_client_secret' => 's2']);
        $this->assertSame('zinnvy-rider', $client('?app=rider'));
        $this->assertSame('zinnvy-inventory', $client(''));
    }
}

<?php

use App\Models\User;
use App\Support\IpAnonymizer;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * A full IP is personal data under GDPR and UU PDP, and the monitoring tables are exactly
 * what would turn a database leak into deanonymisation. Logs only need to tell networks
 * apart, so the host part is masked before anything is written.
 */
it('masks the host part of an IPv4 address', function () {
    expect(IpAnonymizer::mask('192.168.1.77'))->toBe('192.168.1.0')
        ->and(IpAnonymizer::mask('8.8.8.8'))->toBe('8.8.8.0');
});

it('keeps only the routing prefix of an IPv6 address', function () {
    expect(IpAnonymizer::mask('2001:0db8:85a3:0000:0000:8a2e:0370:7334'))
        ->toBe('2001:0db8:85a3::');
});

it('stores nothing for an unparseable address', function () {
    expect(IpAnonymizer::mask('not-an-ip'))->toBeNull()
        ->and(IpAnonymizer::mask(''))->toBe('');
});

/** The masking has to survive all the way into the monitoring table, not just the helper. */
it('never writes a full address to the visit log', function () {
    DB::table('visits_monitoring')->delete();

    actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.45'])
        ->get(route('typing'))
        ->assertOk();

    $logged = DB::table('visits_monitoring')->pluck('ip');

    expect($logged)->not->toBeEmpty()
        ->and($logged)->not->toContain('203.0.113.45');

    foreach ($logged as $ip) {
        expect($ip)->toEndWith('.0');
    }
});

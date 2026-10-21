<?php

use App\Support\Tenancy\DomainNormalizer;

it('normalises user-supplied domains', function (string $input, string $expected) {
    expect(DomainNormalizer::normalize($input))->toBe($expected);
})->with([
    ['ICECREAM.com', 'icecream.com'],
    ['https://www.IceCream.com/', 'www.icecream.com'],
    ['http://icecream.com:8080/shop?x=1#top', 'icecream.com'],
    ['  icecream.com.  ', 'icecream.com'],
    ['user:pass@icecream.com', 'icecream.com'],
]);

it('validates public hostnames', function (string $host, bool $valid) {
    expect(DomainNormalizer::isValid($host))->toBe($valid);
})->with([
    ['icecream.com', true],
    ['www.icecream.co.uk', true],
    ['localhost', false],
    ['127.0.0.1', false],
    ['bad_domain.com', false],
    ['-bad.com', false],
]);

it('computes www/apex alternates', function () {
    expect(DomainNormalizer::alternateHost('www.icecream.com'))->toBe('icecream.com')
        ->and(DomainNormalizer::alternateHost('icecream.com'))->toBe('www.icecream.com')
        ->and(DomainNormalizer::alternateHost('shop.icecream.com'))->toBeNull();
});

it('extracts the platform subdomain label', function () {
    expect(DomainNormalizer::subdomainLabel('icecream.mydomain.test', 'mydomain.test'))->toBe('icecream')
        ->and(DomainNormalizer::subdomainLabel('a.b.mydomain.test', 'mydomain.test'))->toBeNull()
        ->and(DomainNormalizer::subdomainLabel('mydomain.test', 'mydomain.test'))->toBeNull()
        ->and(DomainNormalizer::subdomainLabel('evilmydomain.test', 'mydomain.test'))->toBeNull();
});

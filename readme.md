

## Requirements, recommended environment

- Latest stable and LTS Laravel versions.
- PHP 7+.
- Apache or Nginx.
- MySQL, MariaDB or PostgreSQL.

Please read the full [requirements in the documentation](https://tenancy.dev/docs/hyn/5.4/requirements).

## Installation

```bash
composer require tkeske/hyn-hybrid
```

#### Usage

Example code you can put into your service for creating tenants.

```php
        use Hyn\Tenancy\Database\Connection;

        $dbUsed = Connection::whatDbWillBeUsed();
        $rand = rand(0,9999);
        $tenant = 'tenant' . $rand;
        $website = new Website;
        $website->uuid = $tenant;
        $website->stored_in_database = "tenants" . $dbUsed;
        $website->the_key = Str::random(32);
        $website->tenant_prefix = Connection::whatPrefixWillBeUsed();
        app(WebsiteRepository::class)->create($website);

        $hostname = new Hostname;
        $hostname->fqdn = $tenant . '.domain';
        $hostname = app(HostnameRepository::class)->create($hostname);
        app(HostnameRepository::class)->attach($hostname, $website);
```

## License and contributing

This package is offered under the [MIT license](license.md). In case you're interested at
contributing, make sure to read the [contributing guidelines](.github/CONTRIBUTING.md).

## Contact

Get in touch personally using;

- tomas.keske@post.cz
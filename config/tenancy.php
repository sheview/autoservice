<?php

return [

    /*
    | Hosts of the platform itself. A request to {sub}.{central domain} is resolved
    | to the tenant whose "subdomain" column equals {sub}.
    */
    'central_domains' => array_filter(array_map('trim', explode(',', env('TENANCY_CENTRAL_DOMAINS', 'localhost')))),

    /*
    | How links given to customers (tracking link, QR codes) name the company:
    |   subdomain  https://{subdomain}.{first central domain}/track/{token}  (when companies have their own host)
    |   path       {APP_URL}/t/{company code}/track/{token}               (one shared host: development, early days)
    | Switch it here when going live; every link is built by PublicUrl::forTenant().
    */
    'public_links' => env('TENANCY_PUBLIC_LINKS', 'path'),

];

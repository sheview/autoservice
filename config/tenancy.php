<?php

return [

    /*
    | Hosts of the platform itself. A request to {sub}.{central domain} is resolved
    | to the tenant whose "subdomain" column equals {sub}.
    */
    'central_domains' => array_filter(array_map('trim', explode(',', env('TENANCY_CENTRAL_DOMAINS', 'localhost')))),

];

<?php

// As rotas não têm prefixo /api (apiPrefix ''), então o padrão do Laravel ('api/*') não cobriria nada.
// Liberado para qualquer origem: o Swagger UI roda em outro host, e a autenticação é por Bearer (sem cookies).
return [

    'paths' => ['service-orders/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Accept', 'Content-Type'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];

<?php

/*
| Aucune route web avec session : l'interface est l'application Vue.
| « / » et « /metrics » sont déclarées sans middleware de session dans
| bootstrap/app.php (un scrape Prometheus ne doit pas créer de session).
*/

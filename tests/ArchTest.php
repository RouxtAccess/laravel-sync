<?php

arch()->expect('Rouxtaccess\Sync')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump']);

arch()->preset()->php();

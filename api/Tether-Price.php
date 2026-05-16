<?php

$data = json_decode(file_get_contents("https://gw.arzdigital.com/lahzeh/assets/?slugs=tether"), true);

echo (int) round($data['data'][0]['metrics']['cmc']['price_irt']);

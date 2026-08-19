<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$path = __DIR__ . '/../../templates/routing/admin.twig';
$source = new Twig\Source(file_get_contents($path), 'routing/admin.twig');
$twig = new Twig\Environment(new Twig\Loader\ArrayLoader());
$twig->parse($twig->tokenize($source));

echo "Dynamic routing template test passed\n";

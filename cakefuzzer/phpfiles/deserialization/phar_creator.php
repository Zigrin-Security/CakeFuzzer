<?php

include '../instrumented_functions.php';

// create new Phar
$phar = new Phar("CakeFuzzerDeserializationClass.phar");
$phar->startBuffering();
$phar->addFromString('test.txt', 'text');
$phar->setStub("\xff\xd8\xff\n<?php __HALT_COMPILER(); ?>");

// add object of any class as meta data
$object = new CakeFuzzerDeserializationClass('id');
$phar->setMetadata($object);
$phar->stopBuffering();
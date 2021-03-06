<?php declare(strict_types=1);
$phar = new \Phar(__DIR__ . '/documentor.phar', FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME, 'documentor.phar');
$phar->startBuffering();
$phar->setStub($phar->createDefaultStub('Documentor/src/index.php', 'Documentor/src/index.php'));
$phar->buildFromDirectory(\realpath(__DIR__ . '/..'), '/((Documentor)+(\\/|\\\)+(.*?\\.)(php|css|png|js|ico|txt))$/');
$phar->stopBuffering();
